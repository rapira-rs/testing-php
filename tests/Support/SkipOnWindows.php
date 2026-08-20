<?php

declare(strict_types=1);

namespace Rapira\Sdk\Tests\Support;

use Testo\Pipeline\Attribute\FallbackInterceptor;
use Testo\Pipeline\Attribute\Interceptable;

/**
 * Marks a test (or a whole case) that cannot run on Windows and should be reported as skipped there.
 *
 * Being {@see Interceptable}, it wires {@see SkipOnWindowsInterceptor} (via {@see FallbackInterceptor}):
 * on Windows the interceptor short-circuits the test with a skipped verdict; elsewhere it is a no-op.
 * Used for the rapira acceptance tests — rapira ships no Windows build, so there is nothing to download.
 */
#[\Attribute(\Attribute::TARGET_CLASS | \Attribute::TARGET_METHOD)]
#[FallbackInterceptor(SkipOnWindowsInterceptor::class)]
final readonly class SkipOnWindows implements Interceptable
{
    /**
     * @param non-empty-string $reason Reported as the skip reason.
     */
    public function __construct(
        public string $reason = 'not supported on Windows',
    ) {}
}
