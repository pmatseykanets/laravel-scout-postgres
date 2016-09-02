<?php

namespace ScoutEngines\Postgres;

use Illuminate\Database\ConnectionResolver;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Support\ServiceProvider;
use Laravel\Scout\EngineManager;

class PostgresEngineServiceProvider extends ServiceProvider
{
    public function boot()
    {
        resolve(EngineManager::class)->extend('pgsql', function () {
            return new PostgresEngine($this->app['db'], config('scout.pgsql', []));
        });
    }
}
