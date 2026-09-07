<?php

namespace Innoboxrr\SearchSurge\Search\Filters\Common;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Innoboxrr\SearchSurge\Search\Contracts\Filter;
use Innoboxrr\SearchSurge\Search\Support\DataContainer;
use Innoboxrr\SearchSurge\Search\Utils\DateFilterQuery;
use Innoboxrr\SearchSurge\Search\Utils\Order;

/**
 * Filtro por created_at y updated_at, valido para cualquier modelo.
 *
 *     ?created_at=2026-01-15&operator=>=
 *     ?created_at_start_date=2026-01-01&created_at_end_date=2026-01-31
 *     ?updated_at_operator=<&updated_at=2026-02-01
 *     ?orderBy=created_at&orderMode=desc
 *
 * No usa whereDate(): traduce cada operador a un rango sobre la columna desnuda
 * para que el indice siga sirviendo. Ver DateFilterQuery.
 *
 * Se salta a si mismo si el modelo tiene $timestamps = false, y lee los nombres
 * reales de las columnas por si los has cambiado.
 */
class TimestampsFilter implements Filter
{
    /** @var array<int, string> */
    public static array $keys = [
        'created_at',
        'created_at_operator',
        'created_at_start_date',
        'created_at_end_date',
        'updated_at',
        'updated_at_operator',
        'updated_at_start_date',
        'updated_at_end_date',
        'operator',
        ...Order::KEYS,
    ];

    /**
     * @param Builder<Model> $query
     * @return Builder<Model>
     */
    public static function apply(Builder $query, DataContainer $data): Builder
    {
        $model = $query->getModel();

        if (! $model->usesTimestamps()) {
            return $query;
        }

        foreach (array_filter([$model->getCreatedAtColumn(), $model->getUpdatedAtColumn()]) as $column) {
            $query = DateFilterQuery::apply($query, $data, $column);
            $query = Order::orderBy($query, $data, $column);
        }

        return $query;
    }
}
