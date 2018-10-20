<?php

namespace ScoutEngines\Postgres\TsQuery;

class WebSearchToTsQuery extends BaseTsQueryable
{
    protected $tsFunction = 'websearch_tsquery';
}
