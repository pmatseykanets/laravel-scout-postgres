<?php

namespace ScoutEngines\Postgres;

use Laravel\Scout\Builder;
use Laravel\Scout\Engines\Engine;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\ConnectionResolverInterface;

class PostgresEngine extends Engine
{
    /**
     * Database connection.
     *
     * @var \Illuminate\Database\Connection
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
     * @var array
     */
    protected $config = [];

    /**
     * @var \Illuminate\Database\Eloquent\Model
     */
    protected $model;

    /**
     * Create a new instance of PostgresEngine.
     *
     * @param \Illuminate\Database\ConnectionResolverInterface $resolver
     * @param $config
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
     * @param  \Illuminate\Database\Eloquent\Collection $models
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
     * @param \Illuminate\Database\Eloquent\Model $model
     * @return bool
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

        return $query->insert(
            $data->merge([
                $model->getKeyName() => $model->getKey(),
            ])->all()
        );
    }

    /**
     * Get the indexed value for a given model.
     *
     * @param \Illuminate\Database\Eloquent\Model $model
     * @return string
     */
    protected function toVector(Model $model)
    {
        $fields = collect($model->toSearchableArray())
            ->map(function ($value) {
                return $value === null ? '' : $value;
            });

        $searchConfigString = $this->getSearchConfigString();

        $select = $fields->keys()
            ->map(function ($key) use ($model, $searchConfigString) {
                $vector = "to_tsvector($searchConfigString ?)";
                if ($label = $this->rankFieldWeightLabel($model, $key)) {
                    $vector = "setweight($vector, '$label')";
                }

                return $vector;
            })->implode(' || ');

        return $this->database
            ->query()
            ->selectRaw("$select AS tsvector", $fields->values()->all())
            ->value('tsvector');
    }

    /**
     * Remove the given model from the index.
     *
     * @param  \Illuminate\Database\Eloquent\Collection $models
     * @return void
     */
    public function delete($models)
    {
        $model = $models->first();

        if (! $this->shouldMaintainIndex($model)) {
            return;
        }

        $indexColumn = $this->getIndexColumn($model);
        $key = $model->getKeyName();

        $ids = $models->pluck($key)->all();

        return $this->database
            ->table($model->searchableAs())
            ->whereIn($key, $ids)
            ->update([$indexColumn => null]);
    }

    /**
     * Perform the given search on the engine.
     *
     * @param  \Laravel\Scout\Builder $builder
     * @return mixed
     */
    public function search(Builder $builder)
    {
        return $this->performSearch($builder, $builder->limit);
    }

    /**
     * Perform the given search on the engine.
     *
     * @param  \Laravel\Scout\Builder $builder
     * @param  int $perPage
     * @param  int $page
     * @return mixed
     */
    public function paginate(Builder $builder, $perPage, $page)
    {
        return $this->performSearch($builder, $perPage, $page);
    }

    /**
     * Get the total count from a raw result returned by the engine.
     *
     * @param  mixed $results
     * @return int
     */
    public function getTotalCount($results)
    {
        if (empty($results)) {
            return 0;
        }

        return (int) array_first($results)
            ->total_count;
    }

    /**
     * Perform the given search on the engine.
     *
     * @param \Laravel\Scout\Builder $builder
     * @param int|null $perPage
     * @param int $page
     * @return array
     */
    protected function performSearch(Builder $builder, $perPage = 0, $page = 1)
    {
        // We have to preserve the model in order to allow for
        // correct behavior of mapIds() method which currently
        // does not revceive a model instance
        $this->preserveModel($builder->model);

        $indexColumn = $this->getIndexColumn($builder->model);

        $searchConfigString = $this->getSearchConfigString();

        // Build the query
        $query = $this->database
            ->table($builder->index ?: $builder->model->searchableAs())
            ->crossJoin($this->database->raw("plainto_tsquery($searchConfigString ?) query"))
            ->select($builder->model->getKeyName())
            ->selectRaw("{$this->rankingExpression($builder->model, $indexColumn)} AS rank")
            ->selectRaw('COUNT(*) OVER () AS total_count')
            ->whereRaw("$indexColumn @@ query")
            ->orderBy('rank', 'desc')
            ->orderBy($builder->model->getKeyName());

        if ($perPage > 0) {
            $query->skip(($page - 1) * $perPage)
                ->limit($perPage);
        }

        $bindings = collect([$builder->query]);

        foreach ($builder->wheres as $key => $value) {
            $query->where($key, $value);
            $bindings->push($value);
        }

        return $this->database
            ->select($query->toSql(), $bindings->all());
    }

    /**
     * Pluck and return the primary keys of the given results.
     *
     * @param mixed $results
     * @return \Illuminate\Support\Collection
     */
    public function mapIds($results)
    {
        $keyName = $this->model ? $this->model->getKeyName() : 'id';

        return collect($results)
            ->pluck($keyName)
            ->values();
    }

    /**
     * Map the given results to instances of the given model.
     *
     * @param  mixed $results
     * @param  \Illuminate\Database\Eloquent\Model $model
     * @return Collection
     */
    public function map($results, $model)
    {
        if (empty($results)) {
            return Collection::make();
        }

        $keys = $this->mapIds($results);

        $results = collect($results);

        $models = $model->whereIn($model->getKeyName(), $keys->all())
            ->get()
            ->keyBy($model->getKeyName());

        return $results->map(function ($result) use ($model, $models) {
            return $models[$result->{$model->getKeyName()}];
        });
    }

    /**
     * Connect to the database.
     */
    protected function connect()
    {
        // Already connected
        if ($this->database !== null) {
            return;
        }

        $connection = $this->resolver
            ->connection($this->config('connection'));

        if ($connection->getDriverName() !== 'pgsql') {
            throw new \InvalidArgumentException('Connection should use pgsql driver.');
        }

        $this->database = $connection;
    }

    /**
     * Build ranking expression that will be used in a search.
     *   ts_rank([ weights, ] vector, query [, normalization ])
     *   ts_rank_cd([ weights, ] vector, query [, normalization ]).
     *
     * @param \Illuminate\Database\Eloquent\Model $model
     * @param string $indexColumn
     * @return string
     */
    protected function rankingExpression(Model $model, $indexColumn)
    {
        $args = collect([$indexColumn, 'query']);

        if ($weights = $this->rankWeights($model)) {
            $args->prepend("'$weights'");
        }

        if ($norm = $this->rankNormalization($model)) {
            $args->push($norm);
        }

        $fn = $this->rankFunction($model);

        return "$fn({$args->implode(',')})";
    }

    /**
     * Get rank function.
     *
     * @param \Illuminate\Database\Eloquent\Model $model
     * @return int
     */
    protected function rankFunction(Model $model)
    {
        $default = 'ts_rank';

        $function = $this->option($model, 'rank.function', $default);

        return collect(['ts_rank', 'ts_rank_cd'])->contains($function) ? $function : $default;
    }

    /**
     * Get the rank weight label for a given field.
     *
     * @param \Illuminate\Database\Eloquent\Model $model
     * @param string $field
     * @return string
     */
    protected function rankFieldWeightLabel(Model $model, $field)
    {
        $label = $this->option($model, "rank.fields.$field");

        return collect(['A', 'B', 'C', 'D'])
            ->contains($label) ? $label : '';
    }

    /**
     * Get rank weights.
     *
     * @param \Illuminate\Database\Eloquent\Model $model
     * @return string
     */
    protected function rankWeights(Model $model)
    {
        $weights = $this->option($model, 'rank.weights');

        if (! is_array($weights) || count($weights) !== 4) {
            return '';
        }

        return '{'.implode(',', $weights).'}';
    }

    /**
     * Get rank normalization.
     *
     * @param \Illuminate\Database\Eloquent\Model $model
     * @return int
     */
    protected function rankNormalization(Model $model)
    {
        return $this->option($model, 'rank.normalization', 0);
    }

    /**
     * See if the index should be maintained for a given model.
     *
     * @param \Illuminate\Database\Eloquent\Model|null $model
     * @return bool
     */
    protected function shouldMaintainIndex(Model $model = null)
    {
        if ((bool) $this->config('maintain_index', true) === false) {
            return false;
        }

        if ($model !== null) {
            return $this->option($model, 'maintain_index', true);
        }
    }

    /**
     * Get the name of the column that holds indexed documents.
     *
     * @param \Illuminate\Database\Eloquent\Model $model
     * @return string
     */
    protected function getIndexColumn(Model $model)
    {
        return $this->option($model, 'column', 'searchable');
    }

    /**
     * See if indexed documents are stored in a external table.
     *
     * @param \Illuminate\Database\Eloquent\Model $model
     * @return mixed
     */
    protected function isExternalIndex(Model $model)
    {
        return $this->option($model, 'external', false);
    }

    /**
     * Get the model specific option value or a default.
     *
     * @param \Illuminate\Database\Eloquent\Model $model
     * @param string $key
     * @param mixed $default
     * @return mixed
     */
    protected function option(Model $model, $key, $default = null)
    {
        if (! method_exists($model, 'searchableOptions')) {
            return $default;
        }

        $options = $model->searchableOptions() ?: [];

        return array_get($options, $key, $default);
    }

    /**
     * Get the config value or a default.
     *
     * @param string $key
     * @param mixed $default
     * @return mixed
     */
    protected function config($key, $default = null)
    {
        return array_get($this->config, $key, $default);
    }

    /**
     * @param \Illuminate\Database\Eloquent\Model $model
     */
    protected function preserveModel(Model $model)
    {
        $this->model = $model;
    }

    protected function getSearchConfigString()
    {
        $searchConfig = $this->config('search_configuration');

        $searchConfigString = '';
        if ($searchConfig !== null) {
            $searchConfigString = "'".$searchConfig."',";
        }

        return $searchConfigString;
    }
}
