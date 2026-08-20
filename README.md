<div align="center">

# rapira/testing

**Testing utilities for Rapira: provisions the server binary and runs a live server around your tests**

[![Support on Boosty](https://img.shields.io/static/v1?style=for-the-badge&label=&message=Sponsorship&logo=Boosty&logoColor=white&color=%23F15F2C)](https://boosty.to/roxblnfk)

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
[![License](https://img.shields.io/packagist/l/rapira/testing.svg?style=flat-square)](https://github.com/rapira-rs/sdk-php/blob/1.x/LICENSE.md)
[![Total Downloads](https://img.shields.io/packagist/dt/rapira/testing.svg?style=flat-square)](https://packagist.org/packages/rapira/testing/stats)

The `rapira` binary is downloaded on demand via [DLoad](https://github.com/php-internal/dload) the first time a suite that needs it runs.

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
| `mode`         | `Mode::Worker`     | Run mode passed as `--mode` (`classic`, `worker`, or `dispatcher`).|
| `worker`       | `'worker.php'`     | Entrypoint script, absolute or relative to the working directory.  |
| `address`      | `'127.0.0.1:8080'` | Listen address (`host:port`, `:port`, or `unix:<path>`).           |
| `healthPath`   | `'/'`              | Path polled for readiness; must answer 2xx once the app serves.    |
| `readyTimeout` | `5.0`              | Seconds to wait for the server to answer before failing.           |

## License

BSD-3-Clause. See [LICENSE.md](https://github.com/rapira-rs/sdk-php/blob/1.x/LICENSE.md).
