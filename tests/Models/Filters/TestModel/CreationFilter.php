<?php

namespace Innoboxrr\SearchSurge\Tests\Models\Filters\TestModel;

use Illuminate\Database\Eloquent\Builder;
use Innoboxrr\SearchSurge\Search\Support\DataContainer;
use Innoboxrr\SearchSurge\Search\Utils\CreationFilterQuery;
use Innoboxrr\SearchSurge\Search\Utils\Order;

class CreationFilter
{
    /** @var array<int, string> */
    public static array $keys = [
        ...CreationFilterQuery::KEYS,
        ...Order::KEYS,
    ];

    public static function apply(Builder $query, DataContainer $data)
    {
        $query = CreationFilterQuery::sort($query, $data);

        return Order::orderBy($query, $data, 'created_at');
    }
}
