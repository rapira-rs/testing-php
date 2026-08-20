<?php

declare(strict_types=1);

namespace Rapira\Sdk\Tests\Acceptance\Common;

use Internal\Path;
use Rapira\Sdk\Common\Mode;
use Rapira\Sdk\Testing\Common\DLoader;
use Rapira\Sdk\Testing\Common\Runner;
use Rapira\Sdk\Tests\Support\SkipOnWindows;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Lifecycle\AfterTest;
use Testo\Test;

/**
 * End-to-end coverage for {@see Runner} against a live `rapira serve` process, one test per run mode.
 *
 * Each test boots the real binary over a real socket, hits an HTTP route, and checks the app answered
 * — so it exercises the whole start → HTTP readiness probe → stop cycle. The binary is downloaded once
 * (into `runtime/bin`) and reused. Hits the network on first run; skipped on Windows, which rapira
 * ships no build for.
 */
#[Test]
#[Covers(Runner::class)]
#[SkipOnWindows('rapira ships no Windows build to run')]
final class RunnerTest
{
    private ?Runner $runner = null;

    #[AfterTest]
    public function stopServer(): void
    {
        $this->runner?->stop();
        $this->runner = null;
    }

    public function classicModeAnswersEachRequest(): void
    {
        $body = $this->serveAndGet(Mode::Classic, 'classic.php', '/');

        Assert::string($body)->contains('classic:');
    }

    public function workerModeAnswersFromAResidentScript(): void
    {
        $body = $this->serveAndGet(Mode::Worker, 'worker.php', '/');

        Assert::string($body)->contains('worker:');
    }

    public function dispatcherSyncRoutesRequests(): void
    {
        $address = $this->start(Mode::Dispatcher, 'dispatcher-sync.php');

        Assert::string($this->get("http://{$address}/"))->contains('hello from the sync dispatcher');
        Assert::same($this->get("http://{$address}/echo", 'ping-sync'), 'ping-sync');
    }

    public function dispatcherAsyncRoutesRequests(): void
    {
        $address = $this->start(Mode::Dispatcher, 'dispatcher-async.php');

        Assert::string($this->get("http://{$address}/"))->contains('hello from the async dispatcher');
        Assert::same($this->get("http://{$address}/echo", 'ping-async'), 'ping-async');
    }

    /**
     * Absolute path to the rapira binary, downloading it once into `runtime/bin` if missing.
     *
     * @return non-empty-string
     */
    private static function binary(): string
    {
        static $binary = null;
        if ($binary !== null) {
            return $binary;
        }

        $path = Path::create(\dirname(__DIR__, 3))->join('runtime', 'bin', 'rapira');
        if (!$path->isFile()) {
            (new DLoader())->download($path->parent());
        }

        if (!$path->isFile()) {
            throw new \RuntimeException("rapira binary missing after download: {$path}");
        }

        return $binary = (string) $path;
    }

    /**
     * Absolute path to the fixture application (worker scripts).
     *
     * @return non-empty-string
     */
    private static function appDirectory(): string
    {
        return (string) Path::create(\dirname(__DIR__, 2))->join('Fixtures', 'app');
    }

    /**
     * Ask the OS for a free loopback TCP port.
     */
    private static function freePort(): int
    {
        $socket = \stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        if ($socket === false) {
            throw new \RuntimeException("cannot allocate a port: {$errstr} ({$errno})");
        }

        $name = (string) \stream_socket_get_name($socket, false);
        \fclose($socket);

        return (int) \substr($name, \strrpos($name, ':') + 1);
    }

    /**
     * Start the server for the given mode/worker and GET the health path.
     *
     * @param non-empty-string $worker
     * @param non-empty-string $healthPath
     */
    private function serveAndGet(Mode $mode, string $worker, string $healthPath): string
    {
        $address = $this->start($mode, $worker, $healthPath);

        return $this->get("http://{$address}{$healthPath}");
    }

    /**
     * Boot rapira on a free loopback port and return its `host:port` address.
     *
     * @param non-empty-string $worker
     * @param non-empty-string $healthPath
     * @return non-empty-string
     */
    private function start(Mode $mode, string $worker, string $healthPath = '/'): string
    {
        $this->runner = new Runner(self::binary(), self::appDirectory());
        $port = self::freePort();
        $address = "127.0.0.1:{$port}";

        $this->runner->start($mode, $worker, $address, $healthPath);

        return $address;
    }

    /**
     * GET (or POST, when $body is given) a URL and return the response body.
     *
     * @param non-empty-string $url
     */
    private function get(string $url, ?string $body = null): string
    {
        $http = ['timeout' => 5, 'ignore_errors' => true];
        if ($body !== null) {
            $http['method'] = 'POST';
            $http['header'] = 'content-type: text/plain';
            $http['content'] = $body;
        }

        $response = @\file_get_contents($url, false, \stream_context_create(['http' => $http]));
        if ($response === false) {
            throw new \RuntimeException("request to {$url} failed");
        }

        return $response;
    }
}
