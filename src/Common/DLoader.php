<?php

declare(strict_types=1);

namespace Rapira\Sdk\Testing\Common;

use Internal\DLoad\Bootstrap;
use Internal\DLoad\DLoad;
use Internal\DLoad\Module\Common\OperatingSystem;
use Internal\DLoad\Module\Config\Schema\Action\Download as DownloadConfig;
use Internal\DLoad\Module\Config\Schema\Action\Type;
use Internal\DLoad\Service\Logger as DLoadLogger;
use Internal\Path;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\StyleInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Downloads the rapira server binary through dload's PHP API ({@see DLoad}), logging its steps.
 *
 * Rather than reading a project `dload.xml`, the software definition (GitHub repository and extraction
 * rules) is assembled in memory so the download is fully self-contained: the release asset is pinned to
 * the requested embedded-PHP version, and the binary together with its bundled runtime is extracted
 * into an explicit destination.
 */
final readonly class DLoader
{
    /** @var non-empty-string dload identifier of the rapira software */
    private const SOFTWARE = 'rapira';

    /** @var non-empty-string rapira releases {@see Runner} speaks to; 0.x minors break the CLI and config */
    private const VERSION = '^0.9';

    /**
     * @param LoggerInterface $logger Receives each line of dload output at debug level.
     * @param \SplFileInfo|null $archive Local release asset to extract instead of downloading one from
     * GitHub (no network); it must have the layout of the release for the host OS.
     */
    public function __construct(
        private LoggerInterface $logger = new NullLogger(),
        private ?\SplFileInfo $archive = null,
    ) {}

    /**
     * Download `rapira` and its bundled runtime into $destination; the binary always lands at
     * `{destination}/rapira` (`rapira.exe` on Windows).
     *
     * Linux/macOS — flat extraction: the release tarball nests `bin/rapira` and `lib/rapira/libphp.*`;
     * every file is pulled out by name and dropped side by side into $destination. The shipped binary
     * resolves the library via a relative rpath (`$ORIGIN/../lib/rapira`), so at runtime the loader must
     * be pointed at $destination (e.g. via `LD_LIBRARY_PATH`).
     *
     * Windows — structure-preserving extraction: the zip keeps `rapira.exe` and `php8ts.dll` at its root
     * and the extension DLLs under `ext/`, which the bundled `php.ini` (`extension_dir=ext`) resolves
     * against. Flattening would leave those extensions unloadable.
     *
     * @param Path $destination Directory to extract the release into.
     * @param non-empty-string|null $phpVersion Embedded-PHP version the asset must match; defaults to
     * "8.5" when null.
     *
     * @psalm-suppress InternalMethod, InternalClass, InternalProperty dload's PHP API is `@internal`
     * but relied upon deliberately.
     */
    public function download(Path $destination, ?string $phpVersion = null): void
    {
        $phpVersion ??= '8.5';
        $destination = $destination->absolute();
        $this->logger->debug(
            \sprintf('Downloading %s (php %s) via dload into %s', self::SOFTWARE, $phpVersion, $destination),
        );

        // Capture dload's console output at debug verbosity and replay it through the PSR logger.
        $output = new BufferedOutput(OutputInterface::VERBOSITY_DEBUG);
        $input = new ArrayInput([]);

        # A fresh temp dir per call: on Windows ext/phar keeps a read archive open until the process
        # ends, so a second download of the same asset into the same temp path is denied.
        $tempDir = Path::create(\sys_get_temp_dir())->join('rapira-dload-' . \bin2hex(\random_bytes(6)));

        $container = Bootstrap::init()
            ->withConfig(xml: $this->buildConfig($phpVersion, $tempDir), environment: \getenv())
            ->finish();
        $container->set($input, InputInterface::class);
        $container->set($output, OutputInterface::class);
        $container->set(new SymfonyStyle($input, $output), StyleInterface::class);
        $container->set(new DLoadLogger($output));

        // Target the in-memory software, extracting straight into the requested destination.
        $action = new DownloadConfig();
        $action->software = self::SOFTWARE;
        $action->version = self::VERSION;
        $action->extractPath = (string) $destination;
        # Follows the OS dload resolved for asset selection, so the layout matches the archive it picks.
        if ($container->get(OperatingSystem::class) === OperatingSystem::Windows) {
            $action->type = Type::Archive;
        }

        /** @var DLoad $dload */
        $dload = $container->get(DLoad::class);
        if ($this->archive !== null) {
            $dload->useMock = true;
            $dload->mockArchive = $this->archive;
        }

        $failure = null;
        try {
            $dload->addTask($action)->then(
                null,
                static function (\Throwable $e) use (&$failure): void {
                    $failure = $e;
                },
            );
            $dload->run();
        } catch (\Throwable $e) {
            // A task may fail before it is even scheduled, e.g. when no asset matches the pattern.
            $failure = $e;
        }

        @\rmdir((string) $tempDir);

        // Surface dload's own progress and diagnostics through the logger.
        $this->logLines($output->fetch());

        if ($failure !== null) {
            throw new \RuntimeException(
                \sprintf("dload failed to download '%s': %s", self::SOFTWARE, $failure->getMessage()),
                previous: $failure,
            );
        }
    }

    /**
     * Builds the in-memory dload registry for rapira, baking the requested PHP version into the asset
     * pattern so only the matching release asset is selected.
     *
     * @param non-empty-string $phpVersion
     * @param Path $tempDir Directory dload downloads the asset into before extracting it.
     * @return non-empty-string
     *
     * @psalm-pure
     */
    private function buildConfig(string $phpVersion, Path $tempDir): string
    {
        $phpPattern = \htmlspecialchars(\preg_quote($phpVersion, '/'), ENT_QUOTES | ENT_XML1);
        $tempAttr = \htmlspecialchars((string) $tempDir, ENT_QUOTES | ENT_XML1);

        return <<<XML
            <?xml version="1.0"?>
            <dload temp-dir="{$tempAttr}">
                <registry overwrite="false">
                    <software
                        name="Rapira"
                        alias="rapira"
                        description="PHP application server written in Rust with an embedded PHP interpreter"
                        homepage="https://rapira.rs/"
                    >
                        <repository
                            type="github"
                            uri="rapira-rs/rapira"
                            asset-pattern="/^rapira-v.*-php{$phpPattern}-.*/"
                        />
                        <repository
                            type="github"
                            uri="rapira-rs/rapira-windows"
                            asset-pattern="/^rapira-v.*-php{$phpPattern}-.*/"
                        />
                        <binary name="rapira" />
                        <file pattern="/^.*$/" />
                    </software>
                </registry>
            </dload>
            XML;
    }

    /**
     * Log each non-empty line of captured dload output at debug level.
     */
    private function logLines(string $output): void
    {
        foreach (\preg_split('/\R/', \trim($output)) ?: [] as $line) {
            if ($line !== '') {
                $this->logger->debug($line);
            }
        }
    }
}
