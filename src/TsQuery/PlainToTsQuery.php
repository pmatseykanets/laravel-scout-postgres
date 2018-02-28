<?php

namespace ScoutEngines\Postgres\TsQuery;

class PlainToTsQuery extends BaseTsQueryable
{
    protected $tsFunction = 'plainto_tsquery';
}
