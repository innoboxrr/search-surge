<?php

namespace Innoboxrr\SearchSurge\Search\Utils;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Innoboxrr\SearchSurge\Search\Support\Driver;

/**
 * Conserva el orden por relevancia que devuelve un motor de búsqueda.
 *
 * Cuando Elasticsearch, Algolia o Meilisearch resuelven una consulta, lo
 * valioso no es el conjunto de ids: es el ORDEN. Un `WHERE id IN (...)` lo
 * pierde entero, porque la base devuelve las filas en el orden que le conviene
 * (normalmente el de la clave primaria).
 *
 * Esta clase reconstruye ese orden dentro del SQL, con bindings, adaptándose al
 * motor: FIELD() en MySQL, array_position() en PostgreSQL y un CASE portable en
 * el resto.
 */
class Relevance
{
    /**
     * Ordena la consulta según la posición de cada id en la lista.
     *
     * @param Builder<Model> $query
     * @param array<int, int|string> $ids En orden de relevancia descendente.
     * @return Builder<Model>
     */
    public static function order(Builder $query, array $ids, ?string $column = null): Builder
    {
        $ids = array_values(array_unique(array_filter(
            $ids,
            static fn ($id): bool => is_int($id) || (is_string($id) && $id !== '')
        )));

        if ($ids === []) {
            return $query;
        }

        $column = $column === null
            ? $query->getModel()->getQualifiedKeyName()
            : self::qualify($query, $column);

        [$sql, $bindings] = self::expression($query, $column, $ids);

        return $query->orderByRaw($sql, $bindings);
    }

    /**
     * Aplica el filtro por ids y su orden de relevancia de una vez.
     *
     * Con una lista vacía, whereIn genera `0 = 1`: el motor no encontró nada y
     * la consulta debe devolver cero filas, no todas.
     *
     * @param Builder<Model> $query
     * @param array<int, int|string> $ids
     * @return Builder<Model>
     */
    public static function constrain(Builder $query, array $ids, ?string $column = null): Builder
    {
        $key = $column === null
            ? $query->getModel()->getQualifiedKeyName()
            : self::qualify($query, $column);

        $query->whereIn($key, $ids);

        return self::order($query, $ids, $column);
    }

    /**
     * La expresión de orden para el motor actual.
     *
     * @param Builder<Model> $query
     * @param array<int, int|string> $ids
     * @return array{0: string, 1: array<int, mixed>}
     */
    protected static function expression(Builder $query, string $column, array $ids): array
    {
        $wrapped = $query->getGrammar()->wrap($column);
        $placeholders = implode(', ', array_fill(0, count($ids), '?'));

        return match (Driver::of($query)) {
            'mysql', 'mariadb' => [
                "FIELD({$wrapped}, {$placeholders})",
                $ids,
            ],
            'pgsql' => [
                "array_position(ARRAY[{$placeholders}], {$wrapped})",
                $ids,
            ],
            // CASE es más verboso pero funciona en cualquier motor.
            default => [
                'CASE '.$wrapped.' '
                    .implode(' ', array_map(
                        static fn (int $position): string => 'WHEN ? THEN '.$position,
                        array_keys($ids)
                    ))
                    .' ELSE '.count($ids).' END',
                $ids,
            ],
        };
    }

    /**
     * @param Builder<Model> $query
     */
    protected static function qualify(Builder $query, string $column): string
    {
        return str_contains($column, '.')
            ? $column
            : $query->getModel()->qualifyColumn($column);
    }
}
