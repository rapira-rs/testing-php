<?php

declare(strict_types=1);

namespace Rapira\Sdk\Testing\Testo\Internal;

use Internal\Path;
use Rapira\Sdk\Testing\Common\Runner;
use Rapira\Sdk\Testing\Testo\Attribute\RunRapira;
use Rapira\Sdk\Testing\Testo\RunRapiraPlugin;

/**
 * Suite-level location of the provisioned rapira server: where the binary is and which application it
 * runs. Bound into the suite container by {@see RunRapiraPlugin} so Testo's injector can build
 * {@see RunRapiraInterceptor} (whose other constructor argument is the per-case {@see RunRapira}).
 */
final readonly class RapiraServer
{
    /**
     * @param Path $binary Absolute path to the rapira executable.
     * @param Path $workingDirectory Absolute path to the application directory containing the worker
     * script; the server runs from here and relative worker paths resolve against it.
     * @param Path|null $config Base `rapira.toml`; see {@see Runner::__construct()}.
     */
    public function __construct(
        public Path $binary,
        public Path $workingDirectory,
        public ?Path $config = null,
    ) {}
}
