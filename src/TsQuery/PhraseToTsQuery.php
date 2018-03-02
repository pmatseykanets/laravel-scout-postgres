<?php

namespace ScoutEngines\Postgres\TsQuery;

class PhraseToTsQuery extends BaseTsQueryable
{
    protected $tsFunction = 'phraseto_tsquery';
}
