# Changelog

## [6.0.0](https://github.com/pmatseykanets/laravel-scout-postgres/releases/tag/v6.0.0) - 2019-09-19

### Added

- Added support for Laravel 6

## [5.0.0](https://github.com/pmatseykanets/laravel-scout-postgres/releases/tag/v5.0.0) - 2019-03-13

### Added

- Added support for Scout 7

## [4.0.0](https://github.com/pmatseykanets/laravel-scout-postgres/releases/tag/v4.0.0) - 2018-11-15

### Added

- Added support for Scout 6

## [3.1.0](https://github.com/pmatseykanets/laravel-scout-postgres/releases/tag/v3.1.0) - 2018-10-20

### Added

- Added support for `websearch_to_tsquery()` introduced in PostgreSQL 11

## [3.0.1](https://github.com/pmatseykanets/laravel-scout-postgres/releases/tag/v3.0.1) - 2018-09-02

### Changed

- Fix `composer.json`

## [3.0.0](https://github.com/pmatseykanets/laravel-scout-postgres/releases/tag/v3.0.0) - 2018-09-02

### Changed

- Switched to Scout 5

## [2.3.0](https://github.com/pmatseykanets/laravel-scout-postgres/releases/tag/v2.3.0) - 2018-03-31

### Added

- Allow to choose a `tsquery` producing function

## [2.2.0](https://github.com/pmatseykanets/laravel-scout-postgres/releases/tag/v2.2.0) - 2018-02-26

### Fixed

- Downgraded dependency versions to support php 7.0+ and Laravel 5.4+

## [2.1.0](https://github.com/pmatseykanets/laravel-scout-postgres/releases/tag/v2.1.0) - 2018-02-23

### Added

- Added support for applying `ORDER BY` clauses set on the builder instance

## [2.0.0](https://github.com/pmatseykanets/laravel-scout-postgres/releases/tag/v2.0.0) - 2018-02-09

### Changed

- Switched to Scout 4 (Laravel 5.6) and PHPUnit 7

## [1.0.0](https://github.com/pmatseykanets/laravel-scout-postgres/releases/tag/v1.0.0) - 2017-09-03

### Added

- Added Laravel 5.5 support including package auto discovery

## [0.5.0](https://github.com/pmatseykanets/laravel-scout-postgres/releases/tag/v0.5.0) - 2017-01-30

### Changed

- Updated dependencies to support Laravel 5.4 and Scout 3.0

### Fixed

- Fall back to phpunit 4.8

## [0.4.1](https://github.com/pmatseykanets/laravel-scout-postgres/releases/tag/v0.4.1) - 2017-01-22

### Fixed

- Fixed #7. No longer uses `resolve()` helper to better support Laravel Lumen

## [0.4.0](https://github.com/pmatseykanets/laravel-scout-postgres/releases/tag/v0.4.0) - 2017-01-16

### Added

- Made it possible to specify PostgreSQL search config both globally in scout.php or on per model basis

### Changed

- Fixed #6. Check for models that no longer exist but still present in the index (i.e. soft-deleted models)

## [0.3.0](https://github.com/pmatseykanets/laravel-scout-postgres/releases/tag/v0.3.0) - 2017-01-04

### Changed

- Updated to Scout 2.0
- Fixed an issue with order by clause when performing a search

## [0.2.1](https://github.com/pmatseykanets/laravel-scout-postgres/releases/tag/v0.2.1) - 2016-12-23

### Changed

- Fixed #2. Cast nulls to empty strings in `toVector()`

## [0.2.0](https://github.com/pmatseykanets/laravel-scout-postgres/releases/tag/v0.2.0) - 2016-10-07

### Added

- Implemented `getTotalCount()` method to support length aware pagination

### Changed

- Updated README.md

## [0.1.1](https://github.com/pmatseykanets/laravel-scout-postgres/releases/tag/v0.1.1) - 2016-10-07

### Changed

- Updated composer dependencies

## [0.1.0](https://github.com/pmatseykanets/laravel-scout-postgres/releases/tag/v0.1.0) - 2016-09-02

Experimental release
