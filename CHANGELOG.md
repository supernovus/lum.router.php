# Changelog
All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [1.1.0] - 2023-07-06
### Added
- New `Info` class that splits a bunch of the methods out of `Router`.
### Changed
- The `Router` class now extends the new `Info` class.
- The `requestUri()` method uses `Url::request_uri()` from [lum-web](https://github.com/supernovus/lum.web.php) now.
- Bumped all dependencies to latest versions.

## [1.0.0] - 2022-07-22
### Added
- Initial release, ported from old Nano.php library set.

[Unreleased]: https://github.com/supernovus/lum.router.php/compare/v1.1.0...HEAD
[1.1.0]: https://github.com/supernovus/lum.router.php/compare/v1.0.0...v1.1.0
[1.0.0]: https://github.com/supernovus/lum.router.php/releases/tag/v1.0.0

