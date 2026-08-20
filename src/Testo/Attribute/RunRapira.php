<?php

declare(strict_types=1);

namespace Rapira\Sdk\Testing\Testo\Attribute;

use Rapira\Sdk\Common\Mode;
use Rapira\Sdk\Testing\Testo\Internal\RunRapiraInterceptor;
use Testo\Pipeline\Attribute\FallbackInterceptor;
use Testo\Pipeline\Attribute\Interceptable;

/**
 * Marks a test case whose tests run against a live `rapira` server.
 *
 * Being {@see Interceptable}, it wires {@see RunRapiraInterceptor} (via {@see FallbackInterceptor}):
 * Testo instantiates that interceptor with this attribute instance and runs it around the annotated
 * case, starting `rapira serve` before the case's tests and stopping it afterwards. All customization
 * lives on this attribute; every option has a sensible default.
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
#[FallbackInterceptor(RunRapiraInterceptor::class)]
readonly class RunRapira implements Interceptable
{
    /**
     * @param Mode $mode Server run mode, passed to rapira as `--mode`.
     * @param non-empty-string $worker Entrypoint PHP script rapira runs (the worker file), passed as the
     * positional argument to `rapira serve`. Absolute, or relative to the application working directory.
     * @param non-empty-string $address Listen address, passed to rapira as `--listen`: `host:port`,
     * `:port` (all interfaces), or `unix:<path>`. Also used to reach the server for the readiness probe.
     * @param non-empty-string $healthPath Request path polled for readiness: the server is considered
     * up once an HTTP GET here answers 2xx, so it must map to a route the app always serves (e.g. a
     * hello-world endpoint).
     * @param float $readyTimeout Seconds to wait for the server to answer before failing.
     */
    public function __construct(
        public Mode $mode = Mode::Worker,
        public string $worker = 'worker.php',
        public string $address = '127.0.0.1:8080',
        public string $healthPath = '/',
        public float $readyTimeout = 5.0,
    ) {}
}
