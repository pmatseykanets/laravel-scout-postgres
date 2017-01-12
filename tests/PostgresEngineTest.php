<?php

namespace ScoutEngines\Postgres\Test;

use Mockery;
use Laravel\Scout\Builder;
use Illuminate\Database\Connection;
use Illuminate\Database\Eloquent\Model;
use ScoutEngines\Postgres\PostgresEngine;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\ConnectionResolverInterface;

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
            ->with("to_tsvector( ?) || setweight(to_tsvector( ?), 'B') AS tsvector", ['Foo', ''])
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

        $skip = 0;
        $limit = 5;
        $table = $this->setDbExpectations($db, $skip, $limit);

        $table->shouldReceive('skip')->with($skip)->andReturnSelf()
            ->shouldReceive('limit')->with($limit)->andReturnSelf()
            ->shouldReceive('where')->with('bar', 1);

        $db->shouldReceive('select')->with(null, ['foo', 1]);

        $builder = new Builder(new TestModel(), 'foo');
        $builder->where('bar', 1)->take(5);

        $engine->search($builder);
    }

    public function test_search_configuration()
    {
        $searchConfig = 'simple';
        list($engine, $db) = $this->getEngine(['search_configuration' => $searchConfig]);

        $skip = 0;
        $limit = 5;
        $table = $this->setDbExpectations($db, $skip, $limit, "'".$searchConfig."',");

        $table->shouldReceive('skip')->with($skip)->andReturnSelf()
            ->shouldReceive('limit')->with($limit)->andReturnSelf()
            ->shouldReceive('where')->with('bar', 1);

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
            json_decode('[{"id": 1, "rank": 0.33, "total_count": 1}]'), $model);

        $this->assertCount(1, $results);
    }

    public function test_it_returns_total_count()
    {
        list($engine) = $this->getEngine();

        $count = $engine->getTotalCount(
            json_decode('[{"id": 1, "rank": 0.33, "total_count": 100}]')
        );

        $this->assertEquals(100, $count);
    }

    public function test_map_ids_returns_right_key()
    {
        list($engine, $db) = $this->getEngine();

        $this->setDbExpectations($db);

        $db->shouldReceive('select')
            ->andReturn(json_decode('[{"id": 1}, {"id": 2}]'));

        $builder = new Builder(new TestModel, 'foo');
        $results = $engine->search($builder);
        $ids = $engine->mapIds($results);

        $this->assertInstanceOf(\Illuminate\Support\Collection::class, $ids);
        $this->assertEquals([1, 2], $ids->all());
    }

    protected function getEngine($config = [])
    {
        $resolver = Mockery::mock(ConnectionResolverInterface::class);
        $resolver->shouldReceive('connection')
            ->andReturn($db = Mockery::mock(Connection::class));

        $db->shouldReceive('getDriverName')->andReturn('pgsql');

        return [new PostgresEngine($resolver, $config), $db];
    }

    protected function setDbExpectations($db, $skip = 0, $limit = 5, $searchConfiguration = '')
    {
        $db->shouldReceive('table')
            ->andReturn($table = Mockery::mock('stdClass'));
        $db->shouldReceive('raw')
            ->with("plainto_tsquery($searchConfiguration ?) query")
            ->andReturn("plainto_tsquery($searchConfiguration ?) query");

        $table->shouldReceive('crossJoin')->with("plainto_tsquery($searchConfiguration ?) query")->andReturnSelf()
            ->shouldReceive('select')->with('id')->andReturnSelf()
            ->shouldReceive('selectRaw')->with('ts_rank(searchable,query) AS rank')->andReturnSelf()
            ->shouldReceive('selectRaw')->with('COUNT(*) OVER () AS total_count')->andReturnSelf()
            ->shouldReceive('whereRaw')->andReturnSelf()
            ->shouldReceive('orderBy')->with('rank', 'desc')->andReturnSelf()
            ->shouldReceive('orderBy')->with('id')->andReturnSelf()
            ->shouldReceive('toSql');

        return $table;
    }
}

class TestModel extends Model
{
    public $id = 1;

    public $text = 'Foo';

    protected $searchableOptions = [
        'rank' => [
            'fields' => [
                'nullable' => 'B',
            ],
        ],
    ];

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
        return [
            'text' => $this->text,
            'nullable' => null,
        ];
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
