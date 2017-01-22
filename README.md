# PostrgeSQL Full Text Search Engine for Laravel Scout [WIP]

[![Latest Version on Packagist](https://img.shields.io/packagist/v/pmatseykanets/laravel-scout-postgres.svg?style=flat-square)](https://packagist.org/packages/pmatseykanets/laravel-scout-postgres)
[![Software License](https://img.shields.io/badge/license-MIT-brightgreen.svg?style=flat-square)](LICENSE.md)
[![Build Status](https://img.shields.io/travis/pmatseykanets/laravel-scout-postgres/master.svg?style=flat-square)](https://travis-ci.org/pmatseykanets/laravel-scout-postgres)
[![StyleCI](https://styleci.io/repos/67233265/shield)](https://styleci.io/repos/67233265)
[![Total Downloads](https://img.shields.io/packagist/dt/pmatseykanets/laravel-scout-postgres.svg?style=flat-square)](https://packagist.org/packages/pmatseykanets/laravel-scout-postgres)
[![License](https://poser.pugx.org/pmatseykanets/laravel-scout-postgres/license)](https://github.com/pmatseykanets/laravel-scout-postgres/blob/master/LICENSE.md)



This package makes it easy to use native PostgreSQL Full Text Search capabilities with [Laravel Scout](http://laravel.com/docs/master/scout).

## Contents

- [Installation](#installation)
    - [Scout 1.x](#scout-1x)
    - [Scout 2.x](#scout-2x)
    - [Laravel](#laravel)
    - [Lumen](#lumen)
- [Configuration](#configuration)
    - [Configuring the Engine](#configuring-the-engine)
    - [Configuring PostgreSQL](#configuring-postgresql)
    - [Prepare the Schema](#prepare-the-schema)
    - [Configuring Searchable Data](#configuring-searchable-data)
    - [Configuring the Model](#configuring-the-model)
- [Usage](#usage)
- [Testing](#testing)
- [Security](#security)
- [Changelog](#changelog)
- [Contributing](#contributing)
- [Credits](#credits)
- [License](#license)

## Installation

You can install the package via composer:

### Scout 1.x
``` bash
composer require pmatseykanets/laravel-scout-postgres:0.2.1
```

### Scout 2.x
``` bash
composer require pmatseykanets/laravel-scout-postgres
```

### Laravel
Register the service provider:

```php
// config/app.php
'providers' => [
    ...
    ScoutEngines\Postgres\PostgresEngineServiceProvider::class,
],
```

### Lumen
Scout service provider uses `config_path` helper that is not included in the Lumen.
To fix this include the following snippet either directly in `bootstrap.app` or in your autoloaded helpers file i.e. `app/helpers.php`.

```php
if (! function_exists('config_path')) {
    /**
     * Get the configuration path.
     *
     * @param  string  $path
     * @return string
     */
    function config_path($path = '')
    {
        return app()->basePath() . '/config'.($path ? DIRECTORY_SEPARATOR.$path : $path);
    }
}
```

Create the `scout.php` config file in `app/config` folder with the following contents
```php
<?php

return [
    'driver' => env('SCOUT_DRIVER', 'pgsql'),
    'prefix' => env('SCOUT_PREFIX', ''),
    'queue' => false,
    'pgsql' => [
        'connection' => 'pgsql',
        'maintain_index' => true,
        'config' => 'english',
    ],
];
```

Register service providers:

```php
// bootstrap/app.php
$app->register(Laravel\Scout\ScoutServiceProvider::class);
$app->configure('scout');
$app->register(ScoutEngines\Postgres\PostgresEngineServiceProvider::class);
```

## Configuration

### Configuring the Engine

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
    // You can explicitly specify what PostgreSQL text search config to use by scout.
    // Use \dF in psql to see all available configurations in your database.
    'config' => 'english',
],
...
```

### Configuring PostgreSQL

Make sure that an appropriate [default text search configuration](https://www.postgresql.org/docs/9.5/static/runtime-config-client.html#GUC-DEFAULT-TEXT-SEARCH-CONFIG) is set globbaly (in `postgresql.conf`), for a particular database (`ALTER DATABASE ... SET default_text_search_config TO ...`) or alternatively set `default_text_search_config` in each session.

To check the current value

```sql
SHOW default_text_search_config;
```

### Prepare the Schema

By default the engine expects that parsed documents (model data) are stored in the same table as the Model in a column `searchable` of type `tsvector`. You'd need to create this column and an index in your schema. You can choose between `GIN` and `GiST` indexes in PostgreSQL.

```php
class CreatePostsTable extends Migration
{
    public function up()
    {
        Schema::create('posts', function (Blueprint $table) {
            $table->increments('id');
            $table->text('title');
            $table->text('content')->nullable();
            $table->integer('user_id');
            $table->timestamps();
        });
    
        DB::statement('ALTER TABLE posts ADD searchable tsvector NULL');
        DB::statement('CREATE INDEX posts_searchable_index ON posts USING GIN (searchable)');
        // Or alternatively
        // DB::statement('CREATE INDEX posts_searchable_index ON posts USING GIST (searchable)');
    }
    
    public function down()
    {
        Schema::drop('posts');
    }
}
```

### Configuring Searchable Data

In addition to Model's attributes you can bring other any other data to the index document. I.e. a list of Tags for a Post.

```php
public function toSearchableArray()
{
    return [
        'title' => $this->title,
        'content' => $this->content,
        'author' => $this->user->name,
        'tags' => $this->tags->pluck('tag')->implode(' '),
    ];
}
```

### Configuring the Model

You may fine tune the engine behavior for a particular Model by implemeting `searchableOptions()` in your Model.

```php
class Post extends Model
{
    use Searchable;

	...
    public function searchableOptions()
    {
        return [
            // You may wish to change the default name of the column
            // that holds parsed documents
            'column' => 'indexable',
            // You may want to store the index outside of the Model table
            // In that case let the engine know by setting this parameter to true.
            'external' => true,
            // If you don't want scout to maintain the index for you
            // You can turn it off either for a Model or globally
            'maintain_index' => true,
            // Ranking groups that will be assigned to fields
            // when document is being parsed.
            // Available groups: A, B, C and D.
            'rank' => [
                'fields' => [
                    'title' => 'A',
                    'content' => 'B',
                    'author' => 'D',
                    'tags' => 'C',
                ],
                // Ranking weights for searches.
                // [D-weight, C-weight, B-weight, A-weight].
                // Default [0.1, 0.2, 0.4, 1.0].
                'weights' => [0.1, 0.2, 0.4, 1.0],
                // Ranking function [ts_rank | ts_rank_cd]. Default ts_rank.
                'function' => 'ts_rank_cd',
                // Normalization index. Default 0.
                'normalization' => 32,
            ],
            // You can explicitly specify a PostgreSQL text search configuration for the model.
            // Use \dF in psql to see all available configurationsin your database.
            'config' => 'simple',
        ];
    }
}
...
```

If you decide to keep your Model's index outside of the Model's table you can let engine know that you want to push additional fields in the index table that you can then use to filter the result set by applying `where()` with the Scout `Builder`. In this case you'd need to implement `searchableAdditionalArray()` on your Model. Of course the schema for the external table should include these additional columns.

```php
public function searchableAdditionalArray()
{
    return [
        'user_id' => $this->user_id,
    ];
}
```
You may want to make your searchable column hidden so it's not standing in your way
```php
protected $hidden = [
    'searchable',
];
```

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
