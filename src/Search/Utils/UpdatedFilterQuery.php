<?php

namespace Innoboxrr\SearchSurge\Search\Utils;

use Illuminate\Database\Eloquent\Builder;

/**
 * Filtro por updated_at.
 *
 * Nota: hasta v2 el filtro de rango de esta clase escribía sobre `created_at`
 * por un copy/paste, así que updated_at_start_date / updated_at_end_date
 * filtraban por la columna equivocada. Ya está corregido.
 */
class UpdatedFilterQuery
{
    /**
     * @var array<int, string>
     */
    public const KEYS = [
        'updated_at',
        'updated_at_operator',
        'updated_at_start_date',
        'updated_at_end_date',
        'operator',
    ];

    /**
     * @param Builder<\Illuminate\Database\Eloquent\Model> $query
     * @return Builder<\Illuminate\Database\Eloquent\Model>
     */
    public static function sort(Builder $query, $data): Builder
    {
        return DateFilterQuery::apply($query, $data, 'updated_at');
    }
}
