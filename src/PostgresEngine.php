<?php

namespace ScoutEngines\Postgres;

use Exception;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\PostgresConnection;
use Illuminate\Support\Arr;
use Illuminate\Support\LazyCollection;
use InvalidArgumentException;
use Laravel\Scout\Builder;
use Laravel\Scout\Engines\Engine;
use ScoutEngines\Postgres\TsQuery\PhraseToTsQuery;
use ScoutEngines\Postgres\TsQuery\PlainToTsQuery;
use ScoutEngines\Postgres\TsQuery\ToTsQuery;

class PostgresEngine extends Engine
{
    /**
     * Database connection.
     *
     * @var \Illuminate\Database\PostgresConnection
     */
    protected $database;

    /**
     * Database connection resolver.
     *
     * @var \Illuminate\Database\ConnectionResolverInterface
     */
    protected $resolver;

    /**
     * Config values.
     *
     * @var array<mixed>
     */
    protected $config = [];

    /**
     * @var \Illuminate\Database\Eloquent\Model
     */
    protected $model;

    /**
     * Create a new instance of PostgresEngine.
     *
     * @param  array<mixed>  $config
     */
    public function __construct(ConnectionResolverInterface $resolver, $config)
    {
        $this->resolver = $resolver;
        $this->config = $config;

        $this->connect();
    }

    /**
     * Update the given models in the index.
     *
     * @param  \Illuminate\Database\Eloquent\Collection  $models
     * @return void
     */
    public function update($models)
    {
        if (! $this->shouldMaintainIndex($models->first())) {
            return;
        }

        foreach ($models as $model) {
            $this->performUpdate($model);
        }
    }

    /**
     * Perform update of the given model.
     *
     * @return bool|int
     */
    protected function performUpdate(Model $model)
    {
        $data = collect([$this->getIndexColumn($model) => $this->toVector($model)]);

        $query = $this->database
            ->table($model->searchableAs())
            ->where($model->getKeyName(), '=', $model->getKey());

        if (method_exists($model, 'searchableAdditionalArray')) {
            $data = $data->merge($model->searchableAdditionalArray() ?: []);
        }

        if (! $this->isExternalIndex($model) || $query->exists()) {
            return $query->update($data->all());
        }

        $modelKeyInfo = collect([$model->getKeyName() => $model->getKey()]);

        return $query->insert(
            $data->merge($modelKeyInfo)->all()
        );
    }

    /**
     * Get the indexed value for a given model.
     */
    protected function toVector(Model $model): mixed
    {
        /** @var array<string, mixed> $searchableArray */
        $searchableArray = $model->toSearchableArray();
        $fields = collect($searchableArray)
            ->map(function ($value) {
                return $value === null ? '' : $value;
            });

        $bindings = collect([]);

        // The choices of parser, dictionaries and which types of tokens to index are determined
        // by the selected text search configuration which can be set globally in config/scout.php
        // file or individually for each model in searchableOptions()
        // See https://www.postgresql.org/docs/current/static/textsearch-controls.html
        $vector = 'to_tsvector(COALESCE(?, get_current_ts_config()), ?)';

        $select = $fields->map(function ($value, $key) use ($model, $vector, $bindings) {
            $bindings->push($this->searchConfig($model) ?: null)
                ->push($value);

            // Set a field weight if it was specified in Model's searchableOptions()
            if ($label = $this->rankFieldWeightLabel($model, $key)) {
                $vector = "setweight({$vector}, ?)";
                $bindings->push($label);
            }

            return $vector;
        })->implode(' || ');

        return $this->database
            ->query()
            ->selectRaw("{$select} AS tsvector", $bindings->all())
            ->value('tsvector');
    }

    /**
     * Remove the given model from the index.
     *
     * @param  \Illuminate\Database\Eloquent\Collection  $models
     * @return void
     */
    public function delete($models)
    {
        $model = $models->first();

        if ($model) {
            if (! $this->shouldMaintainIndex($model)) {
                return;
            }

            $indexColumn = $this->getIndexColumn($model);
            $key = $model->getKeyName();

            $ids = $models->pluck($key)->all();

            $this->database
                ->table($model->searchableAs())
                ->whereIn($key, $ids)
                ->update([$indexColumn => null]);

        }
    }

    /**
     * Perform the given search on the engine.
     *
     * @return mixed
     */
    public function search(Builder $builder)
    {
        return $this->performSearch($builder, $builder->limit);
    }

    /**
     * Perform the given search on the engine.
     *
     * @param  int  $perPage
     * @param  int  $page
     * @return mixed
     */
    public function paginate(Builder $builder, $perPage, $page)
    {
        return $this->performSearch($builder, $perPage, $page);
    }

    /**
     * Get the total count from a raw result returned by the engine.
     *
     * @param  mixed  $results
     * @return int
     */
    public function getTotalCount($results)
    {
        if (empty($results)) {
            return 0;
        }

        /** @var array<int, object> $results */
        /** @var object{'id': int, 'rank': string, 'total_count': int} $result */
        $result = Arr::first($results);

        return (int) $result->total_count;
    }

    /**
     * Perform the given search on the engine.
     *
     * @param  int|null  $perPage
     * @param  int  $page
     * @return array<mixed>
     */
    protected function performSearch(Builder $builder, $perPage = 0, $page = 1)
    {
        // We have to preserve the model in order to allow for
        // correct behavior of mapIds() method which currently
        // does not receive a model instance
        $this->preserveModel($builder->model);

        $indexColumn = $this->getIndexColumn($builder->model);

        // Build the SQL query
        $query = $this->database
            ->table($builder->index ?: $builder->model->searchableAs())
            ->select($builder->model->getKeyName())
            ->selectRaw("{$this->rankingExpression($builder->model, $indexColumn)} AS rank")
            ->selectRaw('COUNT(*) OVER () AS total_count')
            ->whereRaw("{$indexColumn} @@ \"tsquery\"");

        // Apply where clauses that were set on the builder instance if any
        foreach ($builder->wheres as $key => $value) {
            $query->where($key, $value);
        }

        // If parsed documents are being stored in the model's table
        if (! $this->isExternalIndex($builder->model)) {
            // and the model uses soft deletes we need to exclude trashed rows
            if ($this->usesSoftDeletes($builder->model)) {
                $query->whereNull($builder->model->getDeletedAtColumn());
            }
        }

        // Apply order by clauses that were set on the builder instance if any
        foreach ($builder->orders as $order) {
            $query->orderBy($order['column'], $order['direction']);
        }

        // Apply default order by clauses (rank and id)
        if (empty($builder->orders)) {
            $query->orderBy('rank', 'desc')
                ->orderBy($builder->model->getKeyName());
        }

        if ($perPage > 0) {
            $query->skip(($page - 1) * $perPage)
                ->limit($perPage);
        }

        // The choices of parser, dictionaries and which types of tokens to index are determined
        // by the selected text search configuration which can be set globally in config/scout.php
        // file or individually for each model in searchableOptions()
        // See https://www.postgresql.org/docs/current/static/textsearch-controls.html
        $tsQuery = $builder->callback
            ? call_user_func($builder->callback, $builder, $this->searchConfig($builder->model), $query)
            : $this->defaultQueryMethod($builder->query, $this->searchConfig($builder->model));

        /** @var \ScoutEngines\Postgres\TsQuery\BaseTsQueryable $tsQuery */
        $query->crossJoin($this->database->raw($tsQuery->sql() . ' AS "tsquery"'));
        // Add TS bindings to the query
        $query->addBinding($tsQuery->bindings(), 'join');

        return $this->database
            ->select($query->toSql(), $query->getBindings());
    }

    /**
     * Returns the default query method.
     *
     * @param  string  $query
     * @param  string  $config
     * @return \ScoutEngines\Postgres\TsQuery\TsQueryable
     */
    public function defaultQueryMethod($query, $config)
    {
        switch (strtolower($this->stringConfig('search_using', 'plainquery'))) {
            case 'tsquery':
                return new ToTsQuery($query, $config);
            case 'phrasequery':
                return new PhraseToTsQuery($query, $config);
            case 'plainquery':
            default:
                return new PlainToTsQuery($query, $config);
        }
    }

    /**
     * Pluck and return the primary keys of the given results.
     *
     * @param  mixed  $results
     * @return \Illuminate\Support\Collection
     */
    public function mapIds($results)
    {
        $keyName = $this->model !== null ? $this->model->getKeyName() : 'id';

        /** @var array<int, object> $results */
        return collect($results)
            ->pluck($keyName)
            ->values();
    }

    /**
     * Map the given results to instances of the given model.
     *
     * @param  mixed  $results
     * @param  \Illuminate\Database\Eloquent\Model  $model
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public function map(Builder $builder, $results, $model)
    {
        $resultModels = Collection::make();

        if (empty($results)) {
            return $resultModels;
        }

        $keys = $this->mapIds($results);

        $models = $model->whereIn($model->getKeyName(), $keys->all())
            ->get()
            ->keyBy($model->getKeyName());

        // The models didn't come out of the database in the correct order.
        // This will map the models into the resultsModel based on the results order.
        /** @var int $key */
        foreach ($keys as $key) {
            if ($models->has($key)) {
                $resultModels->push($models[$key]);
            }
        }

        return $resultModels;
    }

    /**
     * Map the given results to instances of the given model via a lazy collection.
     *
     * @param  mixed  $results
     * @param  \Illuminate\Database\Eloquent\Model  $model
     * @return \Illuminate\Support\LazyCollection
     */
    public function lazyMap(Builder $builder, $results, $model)
    {
        return LazyCollection::make($model->newCollection());
    }

    /**
     * Create a search index.
     *
     * @param  string  $name
     * @param  array<mixed>  $options
     * @return mixed
     */
    public function createIndex($name, $options = [])
    {
        throw new Exception('PostgreSQL indexes should be created through Laravel database migrations.');
    }

    /**
     * Delete a search index.
     *
     * @param  string  $name
     * @return mixed
     */
    public function deleteIndex($name)
    {
        throw new Exception('PostgreSQL indexes should be deleted through Laravel database migrations.');
    }

    /**
     * Connect to the database.
     *
     * @return void
     */
    protected function connect()
    {
        // Already connected
        if ($this->database !== null) {
            return;
        }

        $connection = $this->resolver
            ->connection($this->stringConfig('connection'));

        if ($connection instanceof PostgresConnection) {
            $this->database = $connection;
        } else {
            throw new InvalidArgumentException('Connection should use pgsql driver.');
        }
    }

    /**
     * Build ranking expression that will be used in a search.
     *   ts_rank([ weights, ] vector, query [, normalization ])
     *   ts_rank_cd([ weights, ] vector, query [, normalization ]).
     */
    protected function rankingExpression(Model $model, string $indexColumn): string
    {
        $args = collect([$indexColumn, '"tsquery"']);

        if ($weights = $this->rankWeights($model)) {
            $args->prepend("'{$weights}'");
        }

        if ($norm = $this->rankNormalization($model)) {
            $args->push((string) $norm);
        }

        $fn = $this->rankFunction($model);

        return "{$fn}({$args->implode(',')})";
    }

    /**
     * Get rank function.
     */
    protected function rankFunction(Model $model): string
    {
        $default = 'ts_rank';

        $function = $this->stringOption($model, 'rank.function', $default);

        return collect(['ts_rank', 'ts_rank_cd'])->contains($function) ? $function : $default;
    }

    /**
     * Get the rank weight label for a given field.
     */
    protected function rankFieldWeightLabel(Model $model, string $field): string
    {
        $label = $this->stringOption($model, "rank.fields.{$field}");

        return collect(['A', 'B', 'C', 'D'])
            ->contains($label) ? $label : '';
    }

    /**
     * Get rank weights.
     */
    protected function rankWeights(Model $model): string
    {
        $weights = $this->option($model, 'rank.weights');

        if (! is_array($weights) || count($weights) !== 4) {
            return '';
        }

        return '{' . implode(',', $weights) . '}';
    }

    /**
     * Get rank normalization.
     */
    protected function rankNormalization(Model $model): int
    {
        return $this->intOption($model, 'rank.normalization', 0);
    }

    /**
     * See if the index should be maintained for a given model.
     */
    protected function shouldMaintainIndex(Model $model = null): bool
    {
        if ((bool) $this->config('maintain_index', true) === false) {
            return false;
        }

        if ($model !== null) {
            return (bool) $this->option($model, 'maintain_index', true);
        }

        return false;
    }

    /**
     * Get the name of the column that holds indexed documents.
     */
    protected function getIndexColumn(Model $model): string
    {
        return $this->stringOption($model, 'column', 'searchable');
    }

    /**
     * See if indexed documents are stored in a external table.
     */
    protected function isExternalIndex(Model $model): mixed
    {
        return $this->option($model, 'external', false);
    }

    /**
     * Get the model specific option value or a default.
     *
     * @param  mixed  $default
     */
    protected function option(Model $model, string $key, mixed $default = null): mixed
    {
        if (! method_exists($model, 'searchableOptions')) {
            return $default;
        }

        $options = $model->searchableOptions() ?: [];

        return Arr::get($options, $key, $default);
    }

    /**
     * Get the model specific option value or a default as an int.
     */
    protected function intOption(Model $model, string $key, int $default): int
    {
        $value = $this->option($model, $key, $default);

        if (is_int($value)) {
            return $value;
        } else {
            return $default;
        }
    }

    /**
     * Get the model specific option value or a default as a string.
     */
    protected function stringOption(Model $model, string $key, string $default = ''): string
    {
        $value = $this->option($model, $key, $default);

        if (is_string($value)) {
            return $value;
        } else {
            return $default;
        }
    }

    /**
     * Get the config value or a default.
     *
     * @param  mixed  $default
     */
    protected function config(string $key, mixed $default = null): mixed
    {
        return Arr::get($this->config, $key, $default);
    }

    /**
     * Get the config value or a default as a string.
     */
    protected function stringConfig(string $key, string $default = ''): string
    {
        $value = $this->config($key, $default);

        if (is_string($value)) {
            return $value;
        } else {
            return $default;
        }
    }

    /**
     * @return void
     */
    protected function preserveModel(Model $model)
    {
        $this->model = $model;
    }

    /**
     * Returns a search config name for a model.
     *
     * @return string
     */
    protected function searchConfig(Model $model)
    {
        return $this->stringOption($model, 'config', $this->stringConfig('config', '')) ?: '';
    }

    /**
     * Checks if the model uses the SoftDeletes trait.
     *
     * @return bool
     */
    protected function usesSoftDeletes(Model $model)
    {
        return method_exists($model, 'getDeletedAtColumn');
    }

    /**
     * Flush all of the model's records from the engine.
     *
     * @param  \Illuminate\Database\Eloquent\Model  $model
     * @return void
     */
    public function flush($model)
    {
        if (! $this->shouldMaintainIndex($model)) {
            return;
        }

        $indexColumn = $this->getIndexColumn($model);

        $this->database
            ->table($model->searchableAs())
            ->update([$indexColumn => null]);
    }
}
