<?php

namespace Innoboxrr\SearchSurge\Tests\Fixtures\Filters;

use Illuminate\Database\Eloquent\Builder;
use Innoboxrr\SearchSurge\Search\Support\DataContainer;

class LateFilter
{
    public static int $priority = 50;

    /** @var array<int, string> */
    public static array $order = [];

    public static function apply(Builder $query, DataContainer $data)
    {
        self::$order[] = 'late';

        return $query;
    }
}
