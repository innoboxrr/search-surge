<?php

namespace Innoboxrr\SearchSurge\Tests\Models\Filters\TestModel;

use Illuminate\Database\Eloquent\Builder;
use Innoboxrr\SearchSurge\Search\Support\DataContainer;
use Innoboxrr\SearchSurge\Search\Utils\Order;
use Innoboxrr\SearchSurge\Search\Utils\UpdatedFilterQuery;

class UpdatedFilter
{
    /** @var array<int, string> */
    public static array $keys = [
        ...UpdatedFilterQuery::KEYS,
        ...Order::KEYS,
    ];

    public static function apply(Builder $query, DataContainer $data)
    {
        $query = UpdatedFilterQuery::sort($query, $data);

        return Order::orderBy($query, $data, 'updated_at');
    }
}
