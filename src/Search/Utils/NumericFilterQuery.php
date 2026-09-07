<?php

namespace Innoboxrr\SearchSurge\Search\Utils;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Innoboxrr\SearchSurge\Search\Support\DataContainer;

/**
 * Filtro por una columna numérica, con el mismo vocabulario que las fechas.
 *
 * Lee de los datos:
 *
 *     {columna}            valor suelto, combinado con el operador
 *     {columna}_operator   operador propio de esta columna
 *     operator             operador compartido (mismo fallback que las fechas)
 *     {columna}_min        límite inferior, inclusivo
 *     {columna}_max        límite superior, inclusivo
 *
 * Un valor no numérico se ignora en vez de convertirse en 0. Es la diferencia
 * entre "no filtres por precio" y "dame los de precio 0", y confundirlas es de
 * los errores que más cuesta ver: la consulta funciona y devuelve poco.
 */
class NumericFilterQuery
{
    /**
     * Operadores admitidos, normalizados.
     */
    protected const OPERATORS = [
        '>' => '>',
        '>=' => '>=',
        '<' => '<',
        '<=' => '<=',
        '=' => '=',
        '==' => '=',
        '===' => '=',
        '!=' => '!=',
        '<>' => '!=',
        '!==' => '!=',
    ];

    /**
     * Las claves que consume, para declararlas en el `$keys` de un filtro.
     *
     * @return array<int, string>
     */
    public static function keys(string $column): array
    {
        return [
            $column,
            $column.'_operator',
            $column.'_min',
            $column.'_max',
            'operator',
        ];
    }

    /**
     * @param Builder<Model> $query
     * @return Builder<Model>
     */
    public static function apply(Builder $query, $data, string $column): Builder
    {
        $qualified = self::qualify($query, $column);

        self::applyValue($query, $data, $column, $qualified);
        self::applyRange($query, $data, $column, $qualified);

        return $query;
    }

    /**
     * @param Builder<Model> $query
     */
    protected static function applyValue(Builder $query, $data, string $column, string $qualified): void
    {
        $value = self::number($data, $column);

        if ($value === null) {
            return;
        }

        $operator = self::operator($data, $column);

        if ($operator === null) {
            return;
        }

        $query->where($qualified, $operator, $value);
    }

    /**
     * @param Builder<Model> $query
     */
    protected static function applyRange(Builder $query, $data, string $column, string $qualified): void
    {
        $min = self::number($data, $column.'_min');
        $max = self::number($data, $column.'_max');

        if ($min !== null) {
            $query->where($qualified, '>=', $min);
        }

        if ($max !== null) {
            $query->where($qualified, '<=', $max);
        }
    }

    /**
     * El operador de esta columna, o el compartido.
     *
     * Sin operador no se aplica nada, igual que en los filtros de fecha: un
     * valor suelto no dice si quieres "igual", "al menos" o "como mucho".
     */
    protected static function operator($data, string $column): ?string
    {
        $raw = self::read($data, $column.'_operator') ?? self::read($data, 'operator');

        if ($raw === null || $raw === '') {
            return null;
        }

        return self::OPERATORS[trim((string) $raw)] ?? null;
    }

    /**
     * El valor como número, o null si no lo es.
     */
    protected static function number($data, string $key): int|float|null
    {
        $value = self::read($data, $key);

        if (! is_numeric($value)) {
            return null;
        }

        return $value + 0;
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

    protected static function read($data, string $key): mixed
    {
        if ($data instanceof DataContainer) {
            return $data->get($key);
        }

        return $data->{$key} ?? null;
    }
}
