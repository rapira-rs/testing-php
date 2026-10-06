<?php

declare(strict_types=1);

namespace Rapira\Sdk\Testing\Testo;

use Internal\Container\Container;
use Internal\Path;
use Psr\Log\LoggerInterface;
use Rapira\Sdk\Testing\Common\DLoader;
use Rapira\Sdk\Testing\Testo\Attribute\RunRapira;
use Rapira\Sdk\Testing\Testo\Internal\RapiraServer;
use Rapira\Sdk\Testing\Testo\Internal\RunRapiraInterceptor;
use Testo\Application\Config\Plugin\SuitePlugins;
use Testo\Common\EventListenerCollector;
use Testo\Common\Messenger;
use Testo\Common\PluginConfigurator;
use Testo\Event\TestSuite\TestSuiteStarting;

/**
 * Testo plugin that provisions the `rapira` binary for a suite.
 *
 * When the suite starts, it downloads the binary via dload (unless it is already present). Starting the
 * server itself is left to {@see RunRapiraInterceptor}, which the {@see RunRapira} attribute wires up on
 * its own; this plugin only binds {@see RapiraServer} into the container so that interceptor can be
 * built. The plugin should be attached to a specific suite via {@see SuitePlugins::with()}.
 *
 * @see https://rapira.rs/
 */
final class RunRapiraPlugin implements PluginConfigurator
{
    public const CHANNEL_DLOAD = 'dload';

    private readonly Path $binary;
    private readonly Path $workingDirectory;
    private readonly ?Path $config;

    /**
     * @param Path|non-empty-string $binary Absolute path to the rapira executable. When missing, it is
     * downloaded via dload into its parent directory, together with its bundled PHP runtime.
     * @param Path|non-empty-string $workingDirectory Absolute path to the application directory
     * containing the worker script, from which the server is run.
     * @param non-empty-string|null $phpVersion Embedded-PHP version the downloaded rapira asset must
     * match, e.g. "8.5". When null, {@see DLoader::download()} picks its default.
     * @param Path|non-empty-string|null $config Base `rapira.toml` the server runs with; the listen
     * address, mode and entrypoint from {@see RunRapira} replace its own. When null,
     * `{workingDirectory}/rapira.toml` is used if it exists.
     */
    public function __construct(
        Path|string $binary,
        Path|string $workingDirectory,
        private readonly ?string $phpVersion = null,
        Path|string|null $config = null,
    ) {
        $this->binary = Path::create($binary);
        $this->workingDirectory = Path::create($workingDirectory);
        $this->config = $config === null ? null : Path::create($config);
    }

    #[\Override]
    public function configure(Container $container): void
    {
        $messenger = $container->get(Messenger::class);

        // Download the binary once, when the suite starts.
        $container->get(EventListenerCollector::class)->addListener(
            TestSuiteStarting::class,
            fn() => $this->ensureBinary($messenger->channel(self::CHANNEL_DLOAD)),
        );

        // Expose the binary and application directory so Testo's injector can build
        // RunRapiraInterceptor when it wires up the #[RunRapira] attribute.
        $container->set(new RapiraServer($this->binary, $this->workingDirectory, $this->config));
    }

    /**
     * Ensure the rapira binary is present, downloading it via dload if needed.
     */
    private function ensureBinary(LoggerInterface $logger): void
    {
        if ($this->binary->exists()) {
            return;
        }

        (new DLoader($logger))->download($this->binary->parent(), $this->phpVersion);

        if (!$this->binary->exists()) {
            throw new \RuntimeException("rapira binary not found at: {$this->binary} (dload did not produce it)");
        }
    }
}
