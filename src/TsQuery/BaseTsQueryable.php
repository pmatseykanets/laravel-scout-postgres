<?php

namespace ScoutEngines\Postgres\TsQuery;

abstract class BaseTsQueryable implements TsQueryable
{
    /**
     * Text Search Query.
     *
     * @var string
     */
    public $query;

    /**
     * PostgreSQL Text search configuration.
     *
     * @var string|null
     */
    public $config;

    /**
     * PostgreSQL Text Search Function.
     *
     * @var string
     */
    protected $tsFunction = '';

    /**
     * Create a new instance.
     *
     * @param string $query
     * @param string $config
     */
    public function __construct($query, $config = null)
    {
        $this->query = $query;
        $this->config = $config;
    }

    /**
     * Render the SQL representation.
     *
     * @return string
     */
    public function sql()
    {
        return sprintf('%s(COALESCE(?, get_current_ts_config()), ?)', $this->tsFunction);
    }

    /**
     * Return value bindings for the SQL representation.
     *
     * @return array<mixed>
     */
    public function bindings()
    {
        return [$this->config, $this->query];
    }
}
