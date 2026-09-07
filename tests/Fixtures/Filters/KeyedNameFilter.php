<?php

namespace Innoboxrr\SearchSurge\Tests\Fixtures\Filters;

use Illuminate\Database\Eloquent\Builder;
use Innoboxrr\SearchSurge\Search\Contracts\Filter;
use Innoboxrr\SearchSurge\Search\Support\DataContainer;

/**
 * Declara $keys: SearchSurge no debe ni llamarlo cuando "name" no viene.
 */
class KeyedNameFilter implements Filter
{
    /** Cuantas veces se ha ejecutado, para poder afirmarlo en los tests. */
    public static int $calls = 0;

    /** @var array<int, string> */
    public static array $keys = ['name'];

    public static function apply(Builder $query, DataContainer $data)
    {
        self::$calls++;

        return $query->where('name', 'like', $data->string('name').'%');
    }
}
