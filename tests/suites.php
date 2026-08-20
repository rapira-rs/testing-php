<?php

declare(strict_types=1);

use Testo\Application\Config\FinderConfig;
use Testo\Application\Config\SuiteConfig;

/**
 * Test suites for the `rapira/testing` package, merged into the root {@see testo.php}.
 *
 * Acceptance tests hit the network (real binary downloads) and boot a live server, so they run
 * deliberately rather than as part of a fast unit run; they are skipped on Windows, which rapira
 * ships no build for.
 */
return [
    new SuiteConfig(
        name: 'Testing: Acceptance',
        location: new FinderConfig(
            include: [__DIR__ . '/Acceptance'],
        ),
    ),
];
