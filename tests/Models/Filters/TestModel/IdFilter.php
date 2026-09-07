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
            // Castear tambien aqui, como hace Filters\Common\IdFilter: en
            // PostgreSQL un `id IN ('texto')` contra una columna bigint no
            // devuelve cero filas, lanza un error de tipo.
            $query->whereIn('id', array_map(
                static fn ($v): int => is_numeric($v) ? (int) $v : 0,
                $data->array('ids')
            ));
        }

        return Order::orderBy($query, $data, 'id');
    }
}
