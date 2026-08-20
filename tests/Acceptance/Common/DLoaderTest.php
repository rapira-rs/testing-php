<?php

declare(strict_types=1);

namespace Rapira\Sdk\Tests\Acceptance\Common;

use Internal\Path;
use Rapira\Sdk\Testing\Common\DLoader;
use Rapira\Sdk\Tests\Support\SkipOnWindows;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;

/**
 * End-to-end coverage for {@see DLoader}: downloads the real rapira release from GitHub and checks the
 * binary and its bundled `libphp` land side by side in the destination. Hits the network. Skipped on
 * Windows, which rapira ships no build for.
 */
#[Test]
#[Covers(DLoader::class)]
#[SkipOnWindows('rapira ships no Windows build to download')]
final class DLoaderTest
{
    public function downloadsBinaryAndLibphpIntoDestination(): void
    {
        $destination = Path::create(\sys_get_temp_dir())
            ->join('rapira-dload-' . \bin2hex(\random_bytes(6)));
        \mkdir((string) $destination, 0o777, true);

        try {
            (new DLoader())->download($destination);

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
