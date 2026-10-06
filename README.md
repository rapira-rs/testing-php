<div align="center">

# rapira/testing

</div>

<br />

> [!IMPORTANT]
> ## 🪞 This is a read-only mirror.
>
> Active development lives in [**rapira-rs/sdk-php**](https://github.com/rapira-rs/sdk-php) under `packages/testing/`. This repository is **automatically synchronized** from there on every release.
>
> File issues and pull requests in the [main monorepo](https://github.com/rapira-rs/sdk-php/issues), not here.

## About

Lets tests exercise a PHP application over a real socket instead of mocking the server: it downloads the `rapira` binary for a suite and starts a live `rapira serve` process around your test cases. The core is framework-neutral; a thin [Testo](https://github.com/php-testo/testo) adapter is shipped on top of it.

## Install

```bash
composer require --dev rapira/testing
```

[![PHP](https://img.shields.io/packagist/php-v/rapira/testing.svg?style=flat-square&logo=php)](https://packagist.org/packages/rapira/testing)
[![Latest Version on Packagist](https://img.shields.io/packagist/v/rapira/testing.svg?style=flat-square&logo=packagist)](https://packagist.org/packages/rapira/testing)
[![License](https://img.shields.io/packagist/l/rapira/testing.svg?style=flat-square)](LICENSE.md)
[![Total Downloads](https://img.shields.io/packagist/dt/rapira/testing.svg?style=flat-square)](https://packagist.org/packages/rapira/testing/stats)

The `rapira` binary is downloaded on demand via [DLoad](https://github.com/php-internal/dload) the first time a suite that needs it runs, which talks to the GitHub API: in CI, see [GitHub API limits and the version cache](#github-api-limits-and-the-version-cache).

## Usage with Testo

### 1. Provision the binary for a suite

Attach `RunRapiraPlugin` to the suite in `testo.php`. It downloads the `rapira` binary (once, if missing) and binds the application directory the server runs from.

```php
use Rapira\Sdk\Testing\Testo\RunRapiraPlugin;
use Testo\Application\Config\ApplicationConfig;
use Testo\Application\Config\Plugin\SuitePlugins;
use Testo\Application\Config\SuiteConfig;

return new ApplicationConfig(
    src: ['src'],
    suites: [
        new SuiteConfig(
            name: 'Integration',
            location: ['tests/Integration'],
            plugins: SuitePlugins::with(new RunRapiraPlugin(
                binary: __DIR__ . '/runtime/bin/rapira',
                workingDirectory: __DIR__ . '/tests/Integration/App',
            )),
        ),
    ],
);
```

### 2. Run the server around a test case

Annotate a test case with `#[RunRapira]`. Testo starts `rapira serve` before the case's tests and stops it afterwards.

```php
use Rapira\Sdk\Common\Mode;
use Rapira\Sdk\Testing\Testo\Attribute\RunRapira;
use Testo\Attribute\Test;

#[RunRapira(mode: Mode::Worker, worker: 'worker.php', address: '127.0.0.1:8080')]
final class WorkerTest
{
    #[Test]
    public function respondsToRequests(): void
    {
        $response = file_get_contents('http://127.0.0.1:8080/');

        // ...assertions on $response
    }
}
```

`RunRapira` options:

| Option         | Default            | Description                                                        |
|----------------|--------------------|--------------------------------------------------------------------|
| `mode`         | `Mode::Worker`     | Run mode: `classic`, `worker`, or `dispatcher`.                    |
| `worker`       | `'worker.php'`     | Entrypoint script, absolute or relative to the working directory.  |
| `address`      | `'127.0.0.1:8080'` | Listen address (`host:port`, `:port`, or `unix:<path>`).           |
| `healthPath`   | `'/'`              | Path polled for readiness; must answer 2xx once the app serves.    |
| `readyTimeout` | `5.0`              | Seconds to wait for the server to answer before failing.           |

The server runs with a copy of the application's `rapira.toml` in which `mode`, `worker` and `address` replace `http.pool.mode`, `http.pool.entrypoint` and `http.listen`. The base file is `RunRapiraPlugin`'s `config` argument, or `rapira.toml` in the working directory when that argument is omitted; without either, the server runs on rapira's defaults. The copy is written next to the base file, so relative paths in it keep resolving, and is removed when the server stops.

## Scripting the runtime without a server

Outside a Rapira process the `Rapira\*` functions are the stubs from `rapira/contract`: `get_mode()` answers `Mode::Classic`, and there are no worker requests and no dispatcher. To test code that calls them in another mode, install a `FakeRuntime`; the stubs answer through it until `FakeRuntime::reset()`.

```php
use Rapira\Mode;
use Rapira\Sdk\Testing\Double\FakeRuntime;

$runtime = (new FakeRuntime(Mode::Worker, captureOutput: true))
    ->queue('GET', '/search?q=rapira', cookies: ['sid' => 'abc'])
    ->queue('POST', '/login', post: ['user' => 'alice'])
    ->install();

try {
    $app->run(); // loops over \Rapira\handle_request()
    // $runtime->servedRequests === 2, $runtime->outputs holds what each request printed
} finally {
    FakeRuntime::reset();
}
```

- **Worker mode:** each queued request sets `$_SERVER`, `$_GET`, `$_POST`, `$_COOKIE`, `$_FILES` and `$_REQUEST` while the handler runs; the previous values come back afterwards. As on the host, `$_SERVER` holds only the request's entries and the `REQUEST_TIME` pair, nothing from the process's own. `queue()` derives the usual `$_SERVER` entries from the method and URI and parses the query string; for full control pass a `WorkerRequest`, or a plain array of `$_SERVER` entries, in `requests`. As with the extension, `handle_request()` ignores what the handler returns and gives `false` only once the queue is empty.
- **Output:** with `captureOutput: true` what the handler prints lands in `$runtime->outputs`, one entry per request, instead of the test's own output.
- **Dispatcher mode:** `get_dispatcher()` returns the `dispatcher` you pass.
- **Logs and finished requests:** `log()` calls land in `$runtime->logs`, `rapira_finish_request()` calls in `$runtime->finishedRequests`.

Like the extension, `handle_request()` and `get_dispatcher()` refuse outside their mode. The installed double is process-wide: reset it after every test that installs one.

### HTTP dispatcher mode

`FakeHttpDispatcher` hands out queued `FakeExchange`s in order, then throws `ClosedException` as a drained host does. Each `FakeExchange` records what the worker writes and enforces the host's ordering rules: one final head, body until `$eos`, nothing after finalization.

```php
use Rapira\Mode;
use Rapira\Sdk\Testing\Double\FakeRuntime;
use Rapira\Sdk\Testing\Double\Http\FakeExchange;
use Rapira\Sdk\Testing\Double\Http\FakeHttpDispatcher;

$exchange = FakeExchange::for('/users/42', headers: ['accept' => ['application/json']]);
(new FakeRuntime(Mode::Dispatcher, new FakeHttpDispatcher($exchange)))->install();

$app->run(); // receives from \Rapira\get_dispatcher() until it is drained

// $exchange->status, $exchange->header('content-type'), $exchange->getBody(), $exchange->isFinalized()
```

- **Host-side failures:** `discard()` makes the exchange arrive already cancelled; `discardOnWrite()` makes the first write find it closed; `refuseFiles` makes `sendFile()` throw `FileNotSendableException`.
- **Receive hook:** `FakeHttpDispatcher::$beforeReceive` runs before every `receive()`, e.g. to check what the worker released, and `$receives` counts the calls.

## GitHub API limits and the version cache

Every suite that has to fetch the binary asks the GitHub API which releases `rapira-rs/rapira` (or
`rapira-rs/rapira-windows`) offers. Anonymous requests are capped at 60 per hour per IP — shared with every
other job on the same runner — so a matrix of a few PHP versions and operating systems can exhaust the quota
and fail with a rate-limit error. Two settings remove that risk; dload reads both from the environment, no
`dload.xml` required.

| Variable           | Default                         | Description                                                                                      |
|--------------------|---------------------------------|--------------------------------------------------------------------------------------------------|
| `GITHUB_TOKEN`     | —                               | Token used for GitHub API calls. Raises the limit from 60 to 5000 requests per hour.             |
| `DLOAD_CACHE_DIR`  | per-user cache dir of the OS    | Directory of the version registry — the local database of the releases each repository offers.   |
| `DLOAD_CACHE_TTL`  | `600`                           | Seconds the last check of a repository stays valid. `0` disables the registry entirely.          |

The registry caches release *metadata*, not the archives: while the TTL holds, a repeated run resolves the
version from disk without touching the API. The binary itself is downloaded only when the file named by
`RunRapiraPlugin::$binary` is missing, so a run over an already provisioned `runtime/bin` makes no requests
at all.

### GitHub Actions

Point `DLOAD_CACHE_DIR` at a directory inside the workspace, persist it with `actions/cache`, and pass
`GITHUB_TOKEN` to the step that runs the tests:

```yaml
env:
  DLOAD_CACHE_DIR: ${{ github.workspace }}/runtime/dload-cache

jobs:
  tests:
    steps:
      # ...checkout, setup-php, composer install

      # A cache entry is immutable, so `run_id` keeps the key missing on every run
      # and `restore-keys` reads back the newest entry.
      - name: Restore the dload version registry
        uses: actions/cache@v6
        with:
          path: runtime/dload-cache
          key: dload-registry-${{ github.run_id }}
          restore-keys: dload-registry-

      - name: Run tests
        run: vendor/bin/testo
        env:
          GITHUB_TOKEN: ${{ secrets.GITHUB_TOKEN }}
```

`GITHUB_TOKEN` is provided to every workflow automatically; it needs no extra permissions beyond the default
`contents: read`, since it is used only to read public releases.

When a single suite needs the binary, both settings can be narrowed to the step that runs it instead of the
whole workflow — the rest of the test matrix then neither reads nor writes the registry.
