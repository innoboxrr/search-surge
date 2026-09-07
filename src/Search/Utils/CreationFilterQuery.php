<?php

namespace Innoboxrr\SearchSurge\Search\Utils;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Filtro por created_at.
 *
 * La lógica vive en DateFilterQuery; aquí solo se fija la columna. Ver esa
 * clase para el detalle de por qué ya no se usa whereDate().
 */
class CreationFilterQuery
{
    /**
     * Claves de entrada que consume. Un CreationFilter puede reexportarlas en
     * su propia $keys para que SearchSurge lo salte cuando no venga ninguna.
     *
     * @var array<int, string>
     */
    public const KEYS = [
        'created_at',
        'created_at_operator',
        'created_at_start_date',
        'created_at_end_date',
        'operator',
    ];

    /**
     * @param Builder<Model> $query
     * @return Builder<Model>
     */
    public static function sort(Builder $query, $data): Builder
    {
        return DateFilterQuery::apply($query, $data, 'created_at');
    }
}
