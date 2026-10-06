<?php

declare(strict_types=1);

namespace Rapira\Sdk\Tests\Unit\Common;

use Internal\Path;
use Rapira\Sdk\Testing\Common\DLoader;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Core\Exception\SkipTest;
use Testo\Lifecycle\AfterTest;
use Testo\Lifecycle\BeforeTest;
use Testo\Test;

/**
 * Offline coverage for the layout {@see DLoader} extracts: each test builds a local archive mirroring a
 * real rapira release asset and extracts it in place of a GitHub download. DLoader picks the extraction
 * mode by the host OS, so only the test for the host's layout runs.
 */
#[Test]
#[Covers(DLoader::class)]
final class DLoaderTest
{
    private Path $workDir;

    #[BeforeTest]
    public function createWorkDir(): void
    {
        $this->workDir = Path::create(\sys_get_temp_dir())->join('rapira-dloader-' . \bin2hex(\random_bytes(6)));
        \mkdir((string) $this->workDir, 0o777, true);
    }

    #[AfterTest]
    public function removeWorkDir(): void
    {
        $entries = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator((string) $this->workDir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($entries as $entry) {
            $entry->isDir() ? \rmdir($entry->getPathname()) : \unlink($entry->getPathname());
        }

        \rmdir((string) $this->workDir);
    }

    public function windowsReleaseKeepsExtensionsUnderExtDir(): void
    {
        \PHP_OS_FAMILY === 'Windows' or throw new SkipTest('the Windows release layout applies on Windows only');

        $archive = $this->createArchive('rapira-v1.0.0-php8.5-windows-x86_64.zip', [
            'rapira.exe',
            'php8ts.dll',
            'php.ini',
            'ext/php_fileinfo.dll',
            'ext/php_mbstring.dll',
        ], wrapped: false);
        $destination = $this->workDir->join('bin');

        (new DLoader(archive: $archive))->download($destination);

        Assert::true($destination->join('rapira.exe')->isFile(), 'rapira.exe at the destination root');
        Assert::true($destination->join('php8ts.dll')->isFile(), 'php8ts.dll next to rapira.exe');
        Assert::true($destination->join('php.ini')->isFile(), 'php.ini next to rapira.exe');
        Assert::true(
            $destination->join('ext/php_mbstring.dll')->isFile(),
            'extension DLLs stay under ext/, where php.ini points extension_dir',
        );
        Assert::false($destination->join('php_mbstring.dll')->exists(), 'extension DLLs are not flattened');
    }

    public function unixReleaseIsFlattenedNextToTheBinary(): void
    {
        \PHP_OS_FAMILY === 'Windows' and throw new SkipTest('the tarball layout applies outside Windows only');

        $archive = $this->createArchive('rapira-v1.0.0-php8.5-linux-x86_64.tar.gz', [
            'bin/rapira',
            'lib/rapira/libphp.so',
            'share/php/PHP_VERSION.txt',
        ], wrapped: true);
        $destination = $this->workDir->join('bin');

        (new DLoader(archive: $archive))->download($destination);

        Assert::true($destination->join('rapira')->isFile(), 'rapira at the destination root');
        Assert::true($destination->join('libphp.so')->isFile(), 'libphp.so next to the binary');
        Assert::true($destination->join('PHP_VERSION.txt')->isFile(), 'every other file is flattened too');
    }

    /**
     * Builds an archive with the given entries, like a release asset.
     *
     * @param non-empty-string $name Asset file name; its extension selects the archive format.
     * @param list<non-empty-string> $files Entry paths relative to the archive root.
     * @param bool $wrapped Whether the entries sit under a single directory named after the asset.
     */
    private function createArchive(string $name, array $files, bool $wrapped): \SplFileInfo
    {
        $root = \preg_replace('/\.(zip|tar\.gz)$/', '', $name);
        $isTarGz = \str_ends_with($name, '.tar.gz');
        $path = (string) $this->workDir->join($isTarGz ? $root . '.tar' : $name);
        $prefix = $wrapped ? $root . '/' : '';

        $phar = new \PharData($path);
        foreach ($files as $file) {
            $phar->addFromString($prefix . $file, $file);
        }

        if ($isTarGz) {
            $phar->compress(\Phar::GZ);
            unset($phar);
            \unlink($path);
            $path .= '.gz';
        }

        return new \SplFileInfo($path);
    }
}
