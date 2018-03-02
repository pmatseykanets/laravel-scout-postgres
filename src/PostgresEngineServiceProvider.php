<?php

namespace ScoutEngines\Postgres;

use Laravel\Scout\Builder;
use Laravel\Scout\EngineManager;
use Illuminate\Support\ServiceProvider;
use ScoutEngines\Postgres\TsQuery\ToTsQuery;
use ScoutEngines\Postgres\TsQuery\PlainToTsQuery;
use ScoutEngines\Postgres\TsQuery\PhraseToTsQuery;

class PostgresEngineServiceProvider extends ServiceProvider
{
    public function boot()
    {
        $this->app->make(EngineManager::class)->extend('pgsql', function () {
            return new PostgresEngine($this->app['db'], $this->app['config']->get('scout.pgsql', []));
        });

        if (! Builder::hasMacro('usingPhraseQuery')) {
            Builder::macro('usingPhraseQuery', function () {
                $this->callback = function ($builder, $config) {
                    return new PhraseToTsQuery($builder->query, $config);
                };

                return $this;
            });
        }

        if (! Builder::hasMacro('usingPlainQuery')) {
            Builder::macro('usingPlainQuery', function () {
                $this->callback = function ($builder, $config) {
                    return new PlainToTsQuery($builder->query, $config);
                };

                return $this;
            });
        }

        if (! Builder::hasMacro('usingTsQuery')) {
            Builder::macro('usingTsQuery', function () {
                $this->callback = function ($builder, $config) {
                    return new ToTsQuery($builder->query, $config);
                };

                return $this;
            });
        }
    }
}
