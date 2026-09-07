<?php

namespace Innoboxrr\SearchSurge\Search\Utils;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Innoboxrr\SearchSurge\Search\Support\DataContainer;

/**
 * Filtro por un conjunto de valores: estados, tipos, categorías, ids.
 *
 * Lee de los datos:
 *
 *     {columna}       valores admitidos: "activo", ["a","b"] o "a,b,c"
 *     {columna}_not   valores a excluir, mismo formato
 *
 * Con lista blanca, lo que no está en ella se descarta. Si el usuario mandó
 * algo pero **nada** sobrevive al filtro, la consulta devuelve cero filas en vez
 * de ignorar la condición. Es la respuesta honesta: pediste `?status=inventado`
 * y no hay nada con ese estado. Ignorar la condición devolvería la tabla entera,
 * que es justo lo contrario de lo que pediste.
 *
 * El literal `null` en la lista se traduce a IS NULL, para poder pedir "sin
 * categoría" junto a categorías concretas.
 */
class SetFilterQuery
{
    /**
     * Las claves que consume, para declararlas en el `$keys` de un filtro.
     *
     * @return array<int, string>
     */
    public static function keys(string $column): array
    {
        return [$column, $column.'_not'];
    }

    /**
     * @param Builder<Model> $query
     * @param array<int, string|int> $allowed Lista blanca. Vacía la desactiva.
     * @return Builder<Model>
     */
    public static function apply(Builder $query, $data, string $column, array $allowed = []): Builder
    {
        $qualified = self::qualify($query, $column);

        self::applySet($query, $data, $column, $qualified, $allowed, false);
        self::applySet($query, $data, $column.'_not', $qualified, $allowed, true);

        return $query;
    }

    /**
     * @param Builder<Model> $query
     * @param array<int, string|int> $allowed
     */
    protected static function applySet(
        Builder $query,
        $data,
        string $key,
        string $qualified,
        array $allowed,
        bool $negate
    ): void {
        $values = self::values($data, $key);

        if ($values === []) {
            return;
        }

        $wantsNull = false;

        $values = array_values(array_filter($values, static function ($value) use (&$wantsNull): bool {
            if ($value === null || $value === 'null') {
                $wantsNull = true;

                return false;
            }

            return true;
        }));

        if ($allowed !== []) {
            $values = array_values(array_filter(
                $values,
                static fn ($value): bool => in_array($value, $allowed, false)
            ));
        }

        // Se pidio algo y no quedo nada: cero resultados, no todos.
        if ($values === [] && ! $wantsNull) {
            $negate ? null : $query->whereIn($qualified, []);

            return;
        }

        $query->where(static function (Builder $group) use ($qualified, $values, $wantsNull, $negate): void {
            if ($negate) {
                if ($values !== []) {
                    $group->whereNotIn($qualified, $values);
                }

                if ($wantsNull) {
                    $group->whereNotNull($qualified);
                }

                return;
            }

            if ($values !== []) {
                $group->whereIn($qualified, $values);
            }

            if ($wantsNull) {
                $values === []
                    ? $group->whereNull($qualified)
                    : $group->orWhereNull($qualified);
            }
        });
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
