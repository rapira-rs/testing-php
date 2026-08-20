<?php

declare(strict_types=1);

namespace Rapira\Sdk\Common;

use Rapira\Sdk\Testing\Testo\Attribute\RunRapira;

/**
 * Rapira server run mode, selected via {@see RunRapira}.
 *
 * Mirrors the ladder rapira exposes (see the `[pool] mode` key in `rapira.toml`):
 * - {@see Mode::Classic} runs one PHP script per request, with no dispatcher;
 * - {@see Mode::Worker} keeps a long-lived process pulling requests through the worker loop;
 * - {@see Mode::Dispatcher} runs several units of work concurrently on fibers via the dispatcher.
 */
enum Mode: string
{
    case Classic = 'classic';
    case Worker = 'worker';
    case Dispatcher = 'dispatcher';
}
