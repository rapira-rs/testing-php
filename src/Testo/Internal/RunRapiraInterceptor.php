<?php

declare(strict_types=1);

namespace Rapira\Sdk\Testing\Testo\Internal;

use Rapira\Sdk\Testing\Common\Runner;
use Rapira\Sdk\Testing\Testo\Attribute\RunRapira;
use Rapira\Sdk\Testing\Testo\RunRapiraPlugin;
use Testo\Common\Messenger;
use Testo\Core\Context\CaseInfo;
use Testo\Core\Context\CaseResult;
use Testo\Pipeline\Attribute\InterceptorOptions;
use Testo\Pipeline\Middleware\TestCaseRunInterceptor;

/**
 * Runs a `rapira` server around a test case annotated with {@see RunRapira}.
 *
 * Testo builds one interceptor per {@see RunRapira} occurrence, passing the attribute instance, and
 * runs it around that case: it starts the server — in the mode, on the address, and with the worker
 * script from the attribute — before the case's tests and stops it afterwards. The server lifecycle
 * lives in {@see Runner}; this interceptor only feeds it the attribute's options and a log channel.
 * {@see RunRapiraPlugin} provisions the binary and binds {@see RapiraServer}.
 */
#[InterceptorOptions(order: InterceptorOptions::ORDER_CLOSE_TO_TEST)]
final readonly class RunRapiraInterceptor implements TestCaseRunInterceptor
{
    public const CHANNEL_RAPIRA = 'rapira';

    /**
     * @param RunRapira $config The attribute driving this run (injected by Testo).
     * @param RapiraServer $server The provisioned binary and application directory (bound by the plugin).
     * @param Messenger $messenger Source of the `rapira` log channel handed to the {@see Runner}.
     */
    public function __construct(
        private RunRapira $config,
        private RapiraServer $server,
        private Messenger $messenger,
    ) {}

    #[\Override]
    public function runTestCase(CaseInfo $info, callable $next): CaseResult
    {
        $runner = new Runner(
            $this->server->binary,
            $this->server->workingDirectory,
            $this->messenger->channel(self::CHANNEL_RAPIRA),
        );

        $runner->start(
            $this->config->mode,
            $this->config->worker,
            $this->config->address,
            $this->config->healthPath,
            $this->config->readyTimeout,
        );

        try {
            return $next($info);
        } finally {
            $runner->stop();
        }
    }
}
