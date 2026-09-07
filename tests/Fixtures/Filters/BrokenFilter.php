<?php

namespace Innoboxrr\SearchSurge\Tests\Fixtures\Filters;

use Illuminate\Database\Eloquent\Builder;
use Innoboxrr\SearchSurge\Search\Support\DataContainer;

/**
 * Filtro normal que revienta. Debe registrarse en el log y omitirse.
 */
class BrokenFilter
{
    public static function apply(Builder $query, DataContainer $data)
    {
        throw new \RuntimeException('filtro roto a proposito');
    }
}
