<?php

declare(strict_types=1);

namespace Rapira\Sdk\Tests\Acceptance\Common;

use Internal\Path;
use Rapira\Sdk\Testing\Common\DLoader;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;

/**
 * End-to-end coverage for {@see DLoader}: downloads the real rapira release for the host OS from GitHub
 * and checks the binary and its bundled PHP runtime land where the binary loads them from. Hits the
 * network.
 */
#[Test]
#[Covers(DLoader::class)]
final class DLoaderTest
{
    public function downloadsBinaryAndRuntimeIntoDestination(): void
    {
        $destination = Path::create(\sys_get_temp_dir())
            ->join('rapira-dload-' . \bin2hex(\random_bytes(6)));
        \mkdir((string) $destination, 0o777, true);

        try {
            (new DLoader())->download($destination);

            if (\PHP_OS_FAMILY === 'Windows') {
                Assert::true($destination->join('rapira.exe')->isFile(), 'rapira.exe at the destination root');
                Assert::true($destination->join('php8ts.dll')->isFile(), 'php8ts.dll next to rapira.exe');
                Assert::true(
                    $destination->join('ext/php_mbstring.dll')->isFile(),
                    'extension DLLs under ext/, where php.ini points extension_dir',
                );
                return;
            }

            Assert::true(
                $destination->join('rapira')->isFile(),
                'rapira binary extracted into the destination',
            );
            Assert::true(
                $destination->join('libphp.so')->isFile() || $destination->join('libphp.dylib')->isFile(),
                'bundled libphp extracted next to the binary',
            );
        } finally {
            self::removeTree($destination);
        }
    }

    private static function removeTree(Path $dir): void
    {
        if (!$dir->isDir()) {
            return;
        }

        $entries = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator((string) $dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($entries as $entry) {
            $entry->isDir() ? \rmdir($entry->getPathname()) : \unlink($entry->getPathname());
        }

        \rmdir((string) $dir);
    }
}
