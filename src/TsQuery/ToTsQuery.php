<?php

namespace ScoutEngines\Postgres\TsQuery;

class ToTsQuery extends BaseTsQueryable
{
    protected $tsFunction = 'to_tsquery';
}
