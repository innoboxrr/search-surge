<?php

namespace Innoboxrr\SearchSurge\Tests\Fixtures\Engine;

use Illuminate\Database\Eloquent\Builder;
use Innoboxrr\SearchSurge\Search\Filters\EngineFilter;
use Innoboxrr\SearchSurge\Search\Support\DataContainer;

/**
 * Asi es como se conecta un motor propio sin pasar por Scout: solo se
 * sobrescribe ids().
 */
class FakeEngineFilter extends EngineFilter
{
    protected static function ids(Builder $query, DataContainer $data, string $term): array
    {
        return FakeEngine::search($term, static::limit());
    }
}
