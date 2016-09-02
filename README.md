# PostrgeSQL Full Text Search Engine for Laravel Scout [WIP]

[![Latest Version on Packagist](https://img.shields.io/packagist/v/pmatseykanets/laravel-scout-postgres.svg?style=flat-square)](https://packagist.org/packages/pmatseykanets/laravel-scout-postgres)
[![Software License](https://img.shields.io/badge/license-MIT-brightgreen.svg?style=flat-square)](LICENSE.md)
[![Build Status](https://img.shields.io/travis/pmatseykanets/laravel-scout-postgres/master.svg?style=flat-square)](https://travis-ci.org/pmatseykanets/laravel-scout-postgres)
[![StyleCI](https://styleci.io/repos/67233265/shield)](https://styleci.io/repos/67233265)

This package makes it easy to use native PostgreSQL Full Text Search capabilities with [Laravel Scout](http://laravel.com/docs/master/scout).

## Contents

- [Installation](#installation)
	- [Setting up the HipChat Service](#setting-up-the-hipchat-service)
- [Usage](#usage)
	- [Available Message methods](#available-message-methods)
- [Changelog](#changelog)
- [Testing](#testing)
- [Security](#security)
- [Contributing](#contributing)
- [Credits](#credits)
- [License](#license)

## Installation

You can install the package via composer:

``` bash
composer require pmatseykanets/laravel-scout-postgres
```

You must install the service provider:

```php
// config/app.php
'providers' => [
    ...
    ScoutEngines\PostgresEngineServiceProvider::class,
],
```

Specify the database connection that should be used to access indexed documents in the Laravel Scout configuration file `config/scout.php`:

```php
// config/scout.php
...
'pgsql' => [
    // Connection to use. See config/database.php
    'connection' => env('DB_CONNECTION', 'pgsql'),
    // You may want to update index documents directly in PostgreSQL (i.e. via triggers).
    // In this case you can set this value to false.
    'maintain_index' => true,
],
...
```

## Configuration

TBD

## Usage

Please see the [official documentation](http://laravel.com/docs/master/scout) on how to use Laravel Scout.


## Testing

``` bash
$ composer test
```

## Security

If you discover any security related issues, please email pmatseykanets@gmail.com instead of using the issue tracker.

## Changelog

Please see [CHANGELOG](CHANGELOG.md) for more information what has changed recently.

## Contributing

Please see [CONTRIBUTING](CONTRIBUTING.md) for details.

## Credits

- [Peter Matseykanets](https://github.com/pmatseykanets)
- [All Contributors](../../contributors)

## License

The MIT License (MIT). Please see [License File](LICENSE.md) for more information.
