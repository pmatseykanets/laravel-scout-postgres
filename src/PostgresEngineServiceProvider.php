<?php

namespace ScoutEngines\Postgres;

use Laravel\Scout\EngineManager;
use Illuminate\Support\ServiceProvider;

class PostgresEngineServiceProvider extends ServiceProvider
{
    public function boot()
    {
        resolve(EngineManager::class)->extend('pgsql', function () {
            return new PostgresEngine($this->app['db'], config('scout.pgsql', []));
        });
    }
}
