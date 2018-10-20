<?php

namespace ScoutEngines\Postgres\Test;

use Mockery;
use Laravel\Scout\Builder;
use Laravel\Scout\EngineManager;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\ConnectionResolverInterface;
use ScoutEngines\Postgres\PostgresEngineServiceProvider;

class PostgresEngineServiceProviderTest extends TestCase
{
    public function test_it_boots()
    {
        list($provider) = $this->newProvider();
        $provider->boot();
    }

    public function test_it_creates_macros()
    {
        list($provider) = $this->newProvider();
        $provider->boot();

        $builder = new Builder(Mockery::mock(Model::class), '');

        foreach (PostgresEngineServiceProvider::builderMacros() as $name => $class) {
            $this->assertTrue(Builder::hasMacro($name));

            $callback = $builder->{$name}()->callback;
            $tsFunction = $callback($builder, []);
            $this->assertInstanceOf($class, $tsFunction);
        }
    }

    protected function newProvider()
    {
        $app = Mockery::mock(Application::class);
        $resolver = Mockery::mock(ConnectionResolverInterface::class);
        $app->shouldReceive('get')
            ->with('db')
            ->andReturn($resolver);
        $config = Mockery::mock(Repository::class)
            ->shouldReceive('get')
            ->with('scout.pgsql', [])
            ->andReturn([]);
        $app->shouldReceive('get')
            ->with('config')
            ->andReturn($config);
        $manager = new EngineManager($app);
        $app->shouldReceive('make')
            ->with(EngineManager::class)
            ->once()
            ->andReturn($manager);

        $provider = new PostgresEngineServiceProvider($app);

        return [$provider, $app];
    }
}
