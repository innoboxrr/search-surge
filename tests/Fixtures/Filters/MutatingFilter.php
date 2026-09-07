<?php

namespace Innoboxrr\SearchSurge\Tests\Fixtures\Filters;

use Illuminate\Database\Eloquent\Builder;
use Innoboxrr\SearchSurge\Search\Support\DataContainer;

/**
 * Muta el builder pero se olvida de devolverlo. Es el error mas comun al
 * escribir un filtro, y SearchSurge debe tolerarlo.
 */
class MutatingFilter
{
    public static function apply(Builder $query, DataContainer $data)
    {
        $query->where('name', 'mutado');
    }
}
