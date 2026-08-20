<?php

declare(strict_types=1);

namespace Rapira\Sdk\Testing\Common;

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

    /** @var string|null File the running server's stdout/stderr is redirected to. */
    private ?string $outputFile = null;

    /**
     * @param non-empty-string $binary Absolute path to the rapira executable.
     * @param non-empty-string $workingDirectory Absolute path to the application directory containing
     * the worker script and `rapira.toml`. The server runs with this as its working directory, and
     * relative worker paths resolve against it.
     * @param LoggerInterface $logger Receives the invoked command and readiness at debug level.
     */
    public function __construct(
        private readonly string $binary,
        private readonly string $workingDirectory,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {}

    /**
     * Start the server and wait until it answers an HTTP request. A no-op if one is already running.
     *
     * Readiness is a real HTTP GET to $healthPath, not a bare TCP connect: rapira binds the listen
     * socket before its PHP workers can serve, so a socket that merely accepts connections is not yet
     * a server that answers. The probe is retried until a 2xx response arrives or the timeout elapses.
     *
     * @param non-empty-string $worker Entrypoint PHP script; absolute, or relative to the working
     * directory.
     * @param non-empty-string $address Listen address (`--listen`): `host:port`, `:port`, or
     * `unix:<path>`. Also used to reach the server for the readiness probe.
     * @param non-empty-string $healthPath Request path polled for readiness; must answer 2xx once the
     * app is serving (e.g. a hello-world route).
     * @param float $readyTimeout Seconds to wait for the server to answer before failing.
     */
    public function start(
        Mode $mode,
        string $worker,
        string $address,
        string $healthPath = '/',
        float $readyTimeout = 5.0,
    ): void {
        if ($this->process !== null) {
            return;
        }

        if (!\file_exists($this->binary)) {
            throw new \RuntimeException("rapira binary not found at: {$this->binary} (was it downloaded?)");
        }

        $workerPath = $this->resolveWorker($worker);
        if (!\file_exists($workerPath)) {
            throw new \RuntimeException("rapira worker script not found at: {$workerPath}");
        }

        $command = $this->serveCommand($mode, $address, $workerPath);
        $this->logger->debug("Starting rapira: {$command}");

        // Send stdout/stderr to a file, not a pipe: nothing here reads the pipes, and a worker that
        // fails to boot floods them until the buffer fills and the process blocks. A file also lets
        // waitForReady() replay the server's own diagnostics when it never comes up.
        $nullDevice = \DIRECTORY_SEPARATOR === '\\' ? 'NUL' : '/dev/null';
        $captured = \tempnam(\sys_get_temp_dir(), 'rapira-');
        $this->outputFile = $captured === false ? null : $captured;
        $sink = $this->outputFile ?? $nullDevice;

        $descriptors = [
            0 => ['file', $nullDevice, 'r'], // stdin: rapira reads none
            1 => ['file', $sink, 'a'],       // stdout
            2 => ['file', $sink, 'a'],       // stderr
        ];

        $process = \proc_open($command, $descriptors, $pipes, $this->workingDirectory, $this->serverEnv());
        if (!\is_resource($process)) {
            $this->cleanupOutput();
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
    }

    /**
     * Build the `rapira serve` command: run mode, listen address, and the worker entrypoint. These CLI
     * flags override the corresponding keys in the application's `rapira.toml`.
     *
     * @param non-empty-string $worker Absolute path to the worker script.
     */
    private function serveCommand(Mode $mode, string $address, string $worker): string
    {
        return \sprintf(
            '%s serve --mode %s --listen %s %s',
            \escapeshellarg($this->binary),
            \escapeshellarg($mode->value),
            \escapeshellarg($address),
            \escapeshellarg($worker),
        );
    }

    /**
     * Resolve a worker path against the working directory when it is relative.
     */
    private function resolveWorker(string $worker): string
    {
        return $this->isAbsolutePath($worker)
            ? $worker
            : $this->workingDirectory . '/' . $worker;
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

        $binaryDir = \dirname($this->binary);

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
        if ($this->outputFile === null || !\is_file($this->outputFile)) {
            return '';
        }

        $size = \filesize($this->outputFile);
        if ($size === false || $size === 0) {
            return '';
        }

        $handle = \fopen($this->outputFile, 'rb');
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
        if ($this->outputFile !== null && \is_file($this->outputFile)) {
            @\unlink($this->outputFile);
        }

        $this->outputFile = null;
    }

    /**
     * Whether the path is absolute (Unix `/…`, or Windows `\…` / `C:\…`).
     */
    private function isAbsolutePath(string $path): bool
    {
        return $path !== '' && (
            $path[0] === '/'
                || $path[0] === '\\'
                || (\strlen($path) > 2 && \ctype_alpha($path[0]) && $path[1] === ':')
        );
    }
}
