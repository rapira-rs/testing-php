<?php

declare(strict_types=1);

namespace Rapira\Sdk\Tests\Support;

use Testo\Core\Context\TestInfo;
use Testo\Core\Context\TestResult;
use Testo\Core\Exception\SkipTest;
use Testo\Core\Value\Status;
use Testo\Pipeline\Attribute\InterceptorOptions;
use Testo\Pipeline\Middleware\TestRunInterceptor;

/**
 * Skips a test annotated with {@see SkipOnWindows} when running on Windows.
 *
 * Testo builds one interceptor per {@see SkipOnWindows} occurrence, passing the attribute instance. On
 * Windows it returns a skipped {@see TestResult} instead of calling {@see $next}, so the test body never
 * runs; on any other platform it just forwards to the rest of the pipeline. Producing the result here
 * (rather than throwing {@see SkipTest}) is the interceptor-level way to skip: a throw from an
 * interceptor would abort the pipeline instead.
 */
#[InterceptorOptions(order: InterceptorOptions::ORDER_FILTER)]
final readonly class SkipOnWindowsInterceptor implements TestRunInterceptor
{
    /**
     * @param SkipOnWindows $config The attribute driving this skip (injected by Testo).
     */
    public function __construct(
        private SkipOnWindows $config,
    ) {}

    #[\Override]
    public function runTest(TestInfo $info, callable $next): TestResult
    {
        if (\PHP_OS_FAMILY !== 'Windows') {
            return $next($info);
        }

        return new TestResult(
            info: $info,
            status: Status::Skipped,
            failure: new SkipTest($this->config->reason),
        );
    }
}
