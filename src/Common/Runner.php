<?php

declare(strict_types=1);

namespace Rapira\Sdk\Testing\Common;

use Internal\Path;
use Internal\Toml\Toml;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Rapira\Sdk\Common\Mode;

/**
 * Starts and stops a single `rapira serve` process, logging its steps.
 *
 * {@see start()} launches the server (mode, listen address, and worker entrypoint given per call) and
 * blocks until it answers an HTTP request on the health-check path; {@see stop()} terminates it. The
 * invoked command and readiness are reported through the injected logger at debug level.
 */
final class Runner
{
    /** @var resource|null Running process handle. */
    private $process = null;

    /** File the running server's stdout/stderr is redirected to. */
    private ?Path $outputFile = null;

    /** Configuration generated for the running server. */
    private ?Path $configFile = null;

    private readonly Path $binary;
    private readonly Path $workingDirectory;
    private readonly ?Path $config;

    /**
     * @param Path|non-empty-string $binary Absolute path to the rapira executable.
     * @param Path|non-empty-string $workingDirectory Absolute path to the application directory
     * containing the worker script. The server runs with this as its working directory, and relative
     * worker paths resolve against it.
     * @param LoggerInterface $logger Receives the invoked command and readiness at debug level.
     * @param Path|non-empty-string|null $config Base `rapira.toml` whose settings the server runs with;
     * the listen address, mode and entrypoint given to {@see start()} replace its own. When null,
     * `{workingDirectory}/rapira.toml` is used if it exists, otherwise the server runs on defaults.
     */
    public function __construct(
        Path|string $binary,
        Path|string $workingDirectory,
        private readonly LoggerInterface $logger = new NullLogger(),
        Path|string|null $config = null,
    ) {
        $this->binary = Path::create($binary);
        $this->workingDirectory = Path::create($workingDirectory);
        $this->config = $config === null ? null : Path::create($config);
    }

    /**
     * Start the server and wait until it answers an HTTP request. A no-op if one is already running.
     *
     * Readiness is a real HTTP GET to $healthPath, not a bare TCP connect: rapira binds the listen
     * socket before its PHP workers can serve, so a socket that merely accepts connections is not yet
     * a server that answers. The probe is retried until a 2xx response arrives or the timeout elapses.
     *
     * @param Path|non-empty-string $worker Entrypoint PHP script; absolute, or relative to the working
     * directory.
     * @param non-empty-string $address Listen address (`http.listen`): `host:port`, `:port`, or
     * `unix:<path>`. Also used to reach the server for the readiness probe.
     * @param non-empty-string $healthPath Request path polled for readiness; must answer 2xx once the
     * app is serving (e.g. a hello-world route).
     * @param float $readyTimeout Seconds to wait for the server to answer before failing.
     */
    public function start(
        Mode $mode,
        Path|string $worker,
        string $address,
        string $healthPath = '/',
        float $readyTimeout = 5.0,
    ): void {
        if ($this->process !== null) {
            return;
        }

        if (!$this->binary->exists()) {
            throw new \RuntimeException("rapira binary not found at: {$this->binary} (was it downloaded?)");
        }

        $workerPath = $this->resolveWorker(Path::create($worker));
        if (!$workerPath->exists()) {
            throw new \RuntimeException("rapira worker script not found at: {$workerPath}");
        }

        $this->configFile = $this->writeConfig($mode, $address, $workerPath);
        $command = \sprintf('%s serve %s', \escapeshellarg((string) $this->binary), \escapeshellarg((string) $this->configFile));
        $this->logger->debug("Starting rapira: {$command}");

        // Send stdout/stderr to a file, not a pipe: nothing here reads the pipes, and a worker that
        // fails to boot floods them until the buffer fills and the process blocks. A file also lets
        // waitForReady() replay the server's own diagnostics when it never comes up.
        $nullDevice = \DIRECTORY_SEPARATOR === '\\' ? 'NUL' : '/dev/null';
        $captured = \tempnam(\sys_get_temp_dir(), 'rapira-');
        $this->outputFile = $captured === false ? null : Path::create($captured);
        $sink = $this->outputFile === null ? $nullDevice : (string) $this->outputFile;

        $descriptors = [
            0 => ['file', $nullDevice, 'r'], // stdin: rapira reads none
            1 => ['file', $sink, 'a'],       // stdout
            2 => ['file', $sink, 'a'],       // stderr
        ];

        $process = \proc_open($command, $descriptors, $pipes, (string) $this->workingDirectory, $this->serverEnv());
        if (!\is_resource($process)) {
            $this->cleanupOutput();
            $this->cleanupConfig();
            throw new \RuntimeException('Failed to start rapira process');
        }
        $this->process = $process;

        $this->waitForReady($address, $healthPath, $readyTimeout);
        $this->logger->debug("rapira is ready on {$address} ({$healthPath})");
    }

    /**
     * Terminate the running server, if any.
     *
     * On Windows, uses `taskkill` to kill the process tree. On Unix, sends SIGTERM.
     */
    public function stop(): void
    {
        if ($this->process === null) {
            $this->cleanupOutput();
            $this->cleanupConfig();
            return;
        }

        $status = \proc_get_status($this->process);
        if ($status['running']) {
            if (\DIRECTORY_SEPARATOR === '\\') {
                \exec(\sprintf('taskkill /F /T /PID %d 2>NUL', $status['pid']));
            } else {
                \proc_terminate($this->process, 15);
            }
        }

        \proc_close($this->process);
        $this->process = null;
        $this->cleanupOutput();
        $this->cleanupConfig();
    }

    /**
     * Write the configuration `rapira serve` runs with: the base `rapira.toml`, if any, with the listen
     * address, mode and entrypoint replaced.
     *
     * @param Path $worker Absolute path to the worker script.
     * @return Path The written file.
     */
    private function writeConfig(Mode $mode, string $address, Path $worker): Path
    {
        $base = $this->config ?? $this->workingDirectory->join('rapira.toml');
        if ($this->config === null && !$base->isFile()) {
            $base = null;
        }

        $data = [];
        if ($base !== null) {
            $toml = @\file_get_contents((string) $base);
            $toml === false and throw new \RuntimeException("cannot read rapira config: {$base}");
            /** @var array<string, mixed> $data A TOML document's root is a table, so its keys are strings */
            $data = Toml::parseToArray($toml);
        }

        /** @var mixed $http */
        $http = $data['http'] ?? null;
        \is_array($http) or $http = [];
        /** @var mixed $pool */
        $pool = $http['pool'] ?? null;
        \is_array($pool) or $pool = [];
        $pool['entrypoint'] = (string) $worker;
        $pool['mode'] = $mode->value;
        $http['listen'] = $address;
        $http['pool'] = $pool;
        $data['http'] = $http;

        # rapira resolves relative paths against the config's directory, so the copy sits next to the base.
        $file = ($base === null ? Path::create(\sys_get_temp_dir()) : $base->parent())
            ->join(\sprintf('.rapira-%s.toml', \bin2hex(\random_bytes(6))));
        \file_put_contents((string) $file, (string) Toml::encode($data)) === false
            and throw new \RuntimeException("cannot write rapira config: {$file}");

        return $file;
    }

    /**
     * Resolve a worker path against the working directory when it is relative.
     *
     * @psalm-mutation-free
     */
    private function resolveWorker(Path $worker): Path
    {
        return $worker->isAbsolute() ? $worker : $this->workingDirectory->join($worker);
    }

    /**
     * Environment for the `rapira serve` process.
     *
     * `dload.xml` extracts `libphp.so`/`libphp.dylib` next to the binary (in `runtime/bin`) instead of
     * the rpath-expected `../lib/rapira`, so the dynamic loader has to be pointed at the binary's own
     * directory. Returns `null` on Windows (self-contained `.exe`, nothing to inject) so `proc_open`
     * inherits the parent environment as-is.
     *
     * @return array<string, string>|null
     */
    private function serverEnv(): ?array
    {
        if (\DIRECTORY_SEPARATOR === '\\') {
            return null;
        }

        $binaryDir = (string) $this->binary->parent();

        /** @var array<string, string> $env */
        $env = \getenv();
        foreach (['LD_LIBRARY_PATH', 'DYLD_LIBRARY_PATH'] as $var) {
            $env[$var] = isset($env[$var]) && $env[$var] !== ''
                ? $binaryDir . \PATH_SEPARATOR . $env[$var]
                : $binaryDir;
        }

        return $env;
    }

    /**
     * Poll the health-check path until the server answers with a 2xx response.
     */
    private function waitForReady(string $address, string $healthPath, float $timeout): void
    {
        $deadline = \microtime(true) + $timeout;

        while (\microtime(true) < $deadline) {
            // Fail fast: a crashed worker (bad script, missing runtime) never binds, so waiting the
            // full timeout only delays the inevitable — surface the server's output right away.
            if (!$this->isRunning()) {
                $output = $this->readServerOutput();
                $this->stop();
                throw new \RuntimeException(
                    'rapira exited before it became ready.' . ($output === '' ? '' : "\n{$output}"),
                );
            }

            if ($this->respondsOk($address, $healthPath)) {
                return;
            }

            \usleep(50_000); // 50ms between attempts
        }

        $output = $this->readServerOutput();
        $this->stop();
        throw new \RuntimeException(
            \sprintf('rapira did not become ready within %ss on %s (%s).', $timeout, $address, $healthPath)
            . ($output === '' ? '' : "\n{$output}"),
        );
    }

    /**
     * Whether the server process is still running.
     *
     * @psalm-mutation-free
     */
    private function isRunning(): bool
    {
        if ($this->process === null) {
            return false;
        }

        return \proc_get_status($this->process)['running'];
    }

    /**
     * Whether an HTTP GET to the health path is answered with a 2xx status (TCP or Unix socket).
     */
    private function respondsOk(string $address, string $healthPath): bool
    {
        $socket = $this->openSocket($address, 0.25);
        if ($socket === false) {
            return false;
        }

        $path = $healthPath === '' ? '/' : $healthPath;
        $request = "GET {$path} HTTP/1.0\r\nHost: {$this->hostHeader($address)}\r\nConnection: close\r\n\r\n";

        \stream_set_timeout($socket, 0, 250_000); // 250ms per read: a bound-but-not-serving socket stalls
        $answered = false;
        if (@\fwrite($socket, $request) !== false) {
            $statusLine = @\fgets($socket, 128);
            $answered = \is_string($statusLine)
                && \preg_match('#^HTTP/\d\.\d\s+2\d\d\b#', $statusLine) === 1;
        }
        \fclose($socket);

        return $answered;
    }

    /**
     * Open a client socket to the listen address (Unix socket or TCP).
     *
     * @return resource|false
     */
    private function openSocket(string $address, float $timeout)
    {
        if (\str_starts_with($address, 'unix:')) {
            return @\fsockopen('unix://' . \substr($address, 5), -1, $errno, $errstr, $timeout);
        }

        [$host, $port] = $this->splitHostPort($address);

        return @\fsockopen($host, $port, $errno, $errstr, $timeout);
    }

    /**
     * `Host` header value for the readiness probe.
     */
    private function hostHeader(string $address): string
    {
        if (\str_starts_with($address, 'unix:')) {
            return 'localhost';
        }

        [$host, $port] = $this->splitHostPort($address);

        return "{$host}:{$port}";
    }

    /**
     * Split a `host:port` (or `:port`, meaning all interfaces) address into host and port. An empty
     * host is reached on the loopback interface.
     *
     * @return array{0: string, 1: int}
     */
    private function splitHostPort(string $address): array
    {
        $pos = \strrpos($address, ':');
        $host = $pos === false ? $address : \substr($address, 0, $pos);
        $port = $pos === false ? 0 : (int) \substr($address, $pos + 1);

        return [$host === '' ? '127.0.0.1' : $host, $port];
    }

    /**
     * Tail of the server's captured stdout/stderr, for diagnostics when it fails to come up.
     */
    private function readServerOutput(): string
    {
        if ($this->outputFile === null || !$this->outputFile->isFile()) {
            return '';
        }

        $size = \filesize((string) $this->outputFile);
        if ($size === false || $size === 0) {
            return '';
        }

        $handle = \fopen((string) $this->outputFile, 'rb');
        if ($handle === false) {
            return '';
        }

        $max = 4096;
        $size > $max and \fseek($handle, -$max, \SEEK_END);
        $data = \stream_get_contents($handle);
        \fclose($handle);

        return \is_string($data) ? \trim($data) : '';
    }

    /**
     * Remove the captured-output file, if any.
     */
    private function cleanupOutput(): void
    {
        if ($this->outputFile?->isFile()) {
            @\unlink((string) $this->outputFile);
        }

        $this->outputFile = null;
    }

    /**
     * Remove the generated configuration, if any.
     */
    private function cleanupConfig(): void
    {
        if ($this->configFile?->isFile()) {
            @\unlink((string) $this->configFile);
        }

        $this->configFile = null;
    }
}
