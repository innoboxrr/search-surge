<?php

namespace Innoboxrr\SearchSurge\Tests\Models\Filters\TestModel;

use Illuminate\Database\Eloquent\Builder;
use Innoboxrr\SearchSurge\Search\Utils\Order;

class IdFilter
{

    public static function apply(Builder $query, object $data)
    {

        if ($data->id) {

            $query->where('id', $data->id);

        }

        $query = Order::orderBy($query, $data, 'id');

        return $query;

    }

}
