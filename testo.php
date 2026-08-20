<?php

declare(strict_types=1);

use Testo\Application\Config\ApplicationConfig;
use Testo\Application\Config\FinderConfig;

/**
 * Standalone config for running the `rapira/testing` package on its own (e.g. in the
 * split-published mirror). The root {@see ../../testo.php} reuses the same
 * {@see tests/suites.php} when aggregating every package into one run.
 */
return new ApplicationConfig(
    src: new FinderConfig(['src']),
    suites: require __DIR__ . '/tests/suites.php',
);
