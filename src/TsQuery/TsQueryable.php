<?php

namespace ScoutEngines\Postgres\TsQuery;

interface TsQueryable
{
    /**
     * Render the SQL representation.
     *
     * @return string
     */
    public function sql();

    /**
     * Return value bindings for the SQL representation.
     *
     * @return array
     */
    public function bindings();
}
