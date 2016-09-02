<?php

namespace ScoutEngines\Postgres\Test;

use Mockery;
use Laravel\Scout\Builder;
use Illuminate\Database\Connection;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use ScoutEngines\Postgres\PostgresEngine;

class PostgresEngineTest extends AbstractTestCase
{
    public function test_it_can_be_instantiated()
    {
        list($engine) = $this->getEngine();

        $this->assertInstanceOf(PostgresEngine::class, $engine);
    }

    public function test_update_adds_object_to_index()
    {
        list($engine, $db) = $this->getEngine();

        $db->shouldReceive('query')
            ->andReturn($query = Mockery::mock('stdClass'));
        $query->shouldReceive('selectRaw')
            ->with('to_tsvector(?) AS tsvector', ['Foo'])
            ->andReturnSelf();
        $query->shouldReceive('value')
            ->with('tsvector')
            ->andReturn('foo');

        $db->shouldReceive('table')
            ->andReturn($table = Mockery::mock('stdClass'));
        $table->shouldReceive('where')
            ->with('id', '=', 1)
            ->andReturnSelf();
        $table->shouldReceive('update')
            ->with(['searchable' => 'foo']);

        $engine->update(Collection::make([new TestModel()]));
    }

    public function test_update_do_nothing_if_index_maintenance_turned_off_globally()
    {
        list($engine) = $this->getEngine(['maintain_index' => false]);

        $engine->update(Collection::make([new TestModel()]));
    }

    public function test_delete_removes_object_from_index()
    {
        list($engine, $db) = $this->getEngine();

        $db->shouldReceive('table')
            ->andReturn($table = Mockery::mock('stdClass'));
        $table->shouldReceive('whereIn')
            ->with('id', [1])
            ->andReturnSelf();
        $table->shouldReceive('update')
            ->with(['searchable' => null]);

        $engine->delete(Collection::make([new TestModel()]));
    }

    public function test_delete_do_nothing_if_index_maintenance_turned_off_globally()
    {
        list($engine, $db) = $this->getEngine(['maintain_index' => false]);

        $db->shouldReceive('table')
            ->andReturn($table = Mockery::mock('stdClass'));
        $table->shouldReceive('whereIn')
            ->with('id', [1])
            ->andReturnSelf();
        $table->shouldReceive('update')
            ->with(['searchable' => null]);

        $engine->delete(Collection::make([new TestModel()]));
    }

    public function test_search()
    {
        list($engine, $db) = $this->getEngine();

        $db->shouldReceive('table')
            ->andReturn($table = Mockery::mock('stdClass'));
        $db->shouldReceive('raw')
            ->with('plainto_tsquery(?) query')
            ->andReturn('plainto_tsquery(?) query');

        $table->shouldReceive('crossJoin')->with('plainto_tsquery(?) query')->andReturnSelf()
            ->shouldReceive('select')->with('id')->andReturnSelf()
            ->shouldReceive('selectRaw')->andReturnSelf()
            ->shouldReceive('whereRaw')->andReturnSelf()
            ->shouldReceive('orderBy')->with('rank', 'desc')->andReturnSelf()
            ->shouldReceive('orderBy')->with('id')->andReturnSelf()
            ->shouldReceive('skip')->with(0)->andReturnSelf()
            ->shouldReceive('limit')->with(5)->andReturnSelf()
            ->shouldReceive('where')->with('bar', 1)->andReturnSelf()
            ->shouldReceive('toSql');

        $db->shouldReceive('select')->with(null, ['foo', 1]);

        $builder = new Builder(new TestModel(), 'foo');
        $builder->where('bar', 1)->take(5);

        $engine->search($builder);
    }

    public function test_map_correctly_maps_results_to_models()
    {
        list($engine) = $this->getEngine();

        $model = Mockery::mock('StdClass');
        $model->shouldReceive('getKeyName')->andReturn('id');
        $model->shouldReceive('whereIn')->once()->with('id', [1])->andReturn($model);
        $model->shouldReceive('get')->once()->andReturn(Collection::make([new TestModel()]));

        $results = $engine->map(
            json_decode('[{"id": 1, "rank": 0.33}]'), $model);

        $this->assertCount(1, $results);
    }

    protected function getEngine($config = [])
    {
        $resolver = Mockery::mock(ConnectionResolverInterface::class);
        $resolver->shouldReceive('connection')
            ->andReturn($db = Mockery::mock(Connection::class));

        $db->shouldReceive('getDriverName')->andReturn('pgsql');

        return [new PostgresEngine($resolver, $config), $db];
    }
}

class TestModel extends Model
{
    public $id = 1;

    public $text = 'Foo';

    protected $searchableOptions = [];

    protected $searchableAdditionalArray = [];

    public function searchableAs()
    {
        return 'searchable';
    }

    public function getKeyName()
    {
        return 'id';
    }

    public function getKey()
    {
        return $this->id;
    }

    public function getTable()
    {
        return 'table';
    }

    public function toSearchableArray()
    {
        return ['text' => $this->text];
    }

    public function searchableOptions()
    {
        return $this->searchableOptions;
    }

    public function searchableAdditionalArray()
    {
        return $this->searchableAdditionalArray;
    }
}
