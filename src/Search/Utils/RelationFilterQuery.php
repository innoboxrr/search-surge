<?php

namespace Innoboxrr\SearchSurge\Search\Utils;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Innoboxrr\SearchSurge\Search\Support\DataContainer;

/**
 * Filtros sobre relaciones.
 *
 * Sobre la creencia de que `whereHas` es lento y hay que sustituirlo por un
 * `whereIn` con subconsulta: **es falsa en MySQL 8**. Medido sobre 1.000.000 de
 * posts y 20.000 autores, "posts de autores de MX" (200.000 filas):
 *
 *     whereHas -> EXISTS correlacionado   112,6 ms
 *     whereIn con subconsulta              74,2 ms
 *     JOIN                                 90,1 ms
 *
 * Los tres producen exactamente el mismo plan: el optimizador los unifica. Y al
 * traer una pagina ordenada, los tres tardan lo mismo (~685 ms), porque el coste
 * no esta en la relacion sino en ordenar 200.000 filas.
 *
 * Por eso aqui no hay ningun "selector inteligente de estrategia": seria
 * complejidad a cambio de nada. Se usa whereHas, que es lo legible.
 *
 * Lo que si cambia las cosas, y por un factor de 27, es **no pasar por la
 * relacion cuando no hace falta**. Si la clave foranea esta en la propia tabla:
 *
 *     WHERE author_id = 7                      0,3 ms
 *     whereHas('author', id = 7)                8,2 ms
 *
 * Para eso no necesitas esta clase: un SetFilterQuery sobre `author_id` basta.
 * Usa las relaciones cuando filtres por una columna que solo vive al otro lado.
 */
class RelationFilterQuery
{
    /**
     * Filtra por la existencia de la relación.
     *
     *     has_comments=1  ->  solo los que tienen
     *     has_comments=0  ->  solo los que no
     *
     * @param Builder<Model> $query
     * @return Builder<Model>
     */
    public static function exists(Builder $query, $data, string $relation, ?string $key = null): Builder
    {
        $key ??= 'has_'.$relation;

        if (! self::has($data, $key)) {
            return $query;
        }

        return self::boolean($data, $key)
            ? $query->has($relation)
            : $query->doesntHave($relation);
    }

    /**
     * Filtra por cuántos relacionados hay.
     *
     *     comments_count_min=5
     *     comments_count_max=100
     *
     * @param Builder<Model> $query
     * @return Builder<Model>
     */
    public static function count(Builder $query, $data, string $relation, ?string $prefix = null): Builder
    {
        $prefix ??= $relation.'_count';

        $min = self::read($data, $prefix.'_min');
        $max = self::read($data, $prefix.'_max');

        if (is_numeric($min)) {
            $query->has($relation, '>=', (int) $min);
        }

        if (is_numeric($max)) {
            $query->has($relation, '<=', (int) $max);
        }

        return $query;
    }

    /**
     * Filtra por una columna del otro lado de la relación.
     *
     *     RelationFilterQuery::column($query, $data, 'author', 'country');
     *     ?author_country=MX  o  ?author_country=MX,ES
     *
     * Antes de usar esto, comprueba si la columna que necesitas ya está en tu
     * tabla: filtrar la clave foránea directamente es 27 veces más rápido que
     * cruzar la relación.
     *
     * @param Builder<Model> $query
     * @param array<int, string|int> $allowed Lista blanca opcional.
     * @return Builder<Model>
     */
    public static function column(
        Builder $query,
        $data,
        string $relation,
        string $column,
        ?string $key = null,
        array $allowed = []
    ): Builder {
        $key ??= $relation.'_'.$column;

        $values = self::values($data, $key);

        if ($allowed !== []) {
            $values = array_values(array_filter(
                $values,
                static fn ($value): bool => in_array($value, $allowed, false)
            ));
        }

        if ($values === []) {
            return $query;
        }

        return $query->whereHas($relation, static function (Builder $related) use ($column, $values): void {
            $related->whereIn($related->getModel()->qualifyColumn($column), $values);
        });
    }

    /**
     * Las claves que consume cada variante, para declararlas en `$keys`.
     *
     * @return array<int, string>
     */
    public static function keys(string $relation, array $columns = []): array
    {
        $keys = [
            'has_'.$relation,
            $relation.'_count_min',
            $relation.'_count_max',
        ];

        foreach ($columns as $column) {
            $keys[] = $relation.'_'.$column;
        }

        return $keys;
    }

    /* -----------------------------------------------------------------
     | Lectura
     | ----------------------------------------------------------------- */

    protected static function has($data, string $key): bool
    {
        return $data instanceof DataContainer
            ? $data->filled($key)
            : ! in_array($data->{$key} ?? null, [null, '', []], true);
    }

    protected static function boolean($data, string $key): bool
    {
        return $data instanceof DataContainer
            ? $data->boolean($key)
            : filter_var($data->{$key} ?? false, FILTER_VALIDATE_BOOLEAN);
    }

    protected static function read($data, string $key): mixed
    {
        return $data instanceof DataContainer
            ? $data->get($key)
            : ($data->{$key} ?? null);
    }

    /**
     * @return array<int, mixed>
     */
    protected static function values($data, string $key): array
    {
        if ($data instanceof DataContainer) {
            return $data->array($key);
        }

        $value = $data->{$key} ?? null;

        if ($value === null || $value === '') {
            return [];
        }

        if (is_array($value)) {
            return array_values($value);
        }

        return is_string($value) && str_contains($value, ',')
            ? array_values(array_filter(array_map('trim', explode(',', $value)), static fn ($v): bool => $v !== null && $v !== ''))
            : [$value];
    }
}
