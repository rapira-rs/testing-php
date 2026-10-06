# Changelog

## [0.1.7](https://github.com/rapira-rs/sdk-php/compare/testing-0.1.6...testing-0.1.7) (2026-10-06)


### Bug Fixes

* **testing:** build a request's $_SERVER from the request alone ([f9e5e84](https://github.com/rapira-rs/sdk-php/commit/f9e5e84712172e2a3f7598607c8488f0f458c053))
* **testing:** return true from every served FakeRuntime request ([10279b9](https://github.com/rapira-rs/sdk-php/commit/10279b9befc6b80ebe6b76970f2e5dfc81ba19f0))

## [0.1.6](https://github.com/rapira-rs/sdk-php/compare/testing-0.1.5...testing-0.1.6) (2026-10-06)


### Features

* **testing:** add FakeHttpDispatcher and FakeExchange ([97afe49](https://github.com/rapira-rs/sdk-php/commit/97afe499d8c87a29a30e670fc74eb7fd67d3fc61))
* **testing:** add FakeRuntime to script the Rapira functions in tests ([0499f97](https://github.com/rapira-rs/sdk-php/commit/0499f97a52c40effa5cac34ad64db128640fb995))


### Code Refactoring

* **testing:** carry file paths as Path ([aba7d3b](https://github.com/rapira-rs/sdk-php/commit/aba7d3b51d1f661d0fbcbe0559c9646f7baa15d1))

## [0.1.5](https://github.com/rapira-rs/sdk-php/compare/testing-0.1.4...testing-0.1.5) (2026-10-06)


### Bug Fixes

* **testing:** give each dload download its own temp dir so a repeated download works on Windows ([8fc0d6e](https://github.com/rapira-rs/sdk-php/commit/8fc0d6e5024da0f7c9464aada0a115e4e373508b))
* **testing:** keep the Windows release layout so bundled PHP extensions load ([228df32](https://github.com/rapira-rs/sdk-php/commit/228df324bd1d4a7ec7f6af1ce9d876774fed5fec))
* **testing:** run rapira 0.9 through a generated rapira.toml ([8fc0d6e](https://github.com/rapira-rs/sdk-php/commit/8fc0d6e5024da0f7c9464aada0a115e4e373508b))


### Documentation

* **testing:** document the GitHub token and dload version cache ([97f301b](https://github.com/rapira-rs/sdk-php/commit/97f301b867619241d536481724b9709cd917218d))

## [0.1.4](https://github.com/rapira-rs/sdk-php/compare/testing-0.1.3...testing-0.1.4) (2026-09-05)


### Bug Fixes

* update asset patterns for rapira binary and libphp file matching ([3d62cda](https://github.com/rapira-rs/sdk-php/commit/3d62cdaa8fd5c019de571089a789eeeb5e5c3205))

## [0.1.3](https://github.com/rapira-rs/sdk-php/compare/testing-0.1.2...testing-0.1.3) (2026-09-05)


### Features

* add support for rapira-windows repository in DLoader ([5c82c1f](https://github.com/rapira-rs/sdk-php/commit/5c82c1f4aff2b2d9206b3106a335aeedb7e39b53))

## [0.1.2](https://github.com/rapira-rs/sdk-php/compare/testing-0.1.1...testing-0.1.2) (2026-08-20)


### Documentation

* trim package READMEs and ship a per-package LICENSE.md ([4e90f9f](https://github.com/rapira-rs/sdk-php/commit/4e90f9fc3dcd0859f1103015023f27cbe93a33c6))

## [0.1.1](https://github.com/rapira-rs/sdk-php/compare/testing-0.1.0...testing-0.1.1) (2026-08-20)


### Features

* **testing:** add package README ([bc1ff59](https://github.com/rapira-rs/sdk-php/commit/bc1ff59fce72583caafc1c9ee4ab88f14e2c0f07))


### Documentation

* capitalize the Rapira product name ([1fa21fb](https://github.com/rapira-rs/sdk-php/commit/1fa21fbcf220f86bf551eff940b2c9a31f5d5083))
