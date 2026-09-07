<?php

namespace Innoboxrr\SearchSurge\Tests\Models\Filters\TestModel;

use Illuminate\Database\Eloquent\Builder;
use Innoboxrr\SearchSurge\Search\Support\DataContainer;
use Innoboxrr\SearchSurge\Search\Utils\Order;

class IdFilter
{
    public static function apply(Builder $query, DataContainer $data)
    {
        if ($data->filled('id')) {
            $query->where('id', $data->integer('id'));
        }

        if ($data->filled('ids')) {
            $query->whereIn('id', $data->array('ids'));
        }

        return Order::orderBy($query, $data, 'id');
    }
}
