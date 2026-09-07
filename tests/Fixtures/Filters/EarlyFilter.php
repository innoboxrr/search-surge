<?php

namespace Innoboxrr\SearchSurge\Tests\Fixtures\Filters;

use Illuminate\Database\Eloquent\Builder;
use Innoboxrr\SearchSurge\Search\Support\DataContainer;

class EarlyFilter
{
    public static int $priority = -50;

    public static function apply(Builder $query, DataContainer $data)
    {
        LateFilter::$order[] = 'early';

        return $query;
    }
}
