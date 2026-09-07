<?php

namespace Innoboxrr\SearchSurge\Search\Utils;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Innoboxrr\SearchSurge\Search\Support\DataContainer;

/**
 * Motor compartido de los filtros por fecha.
 *
 * El punto importante es que aquí NO se usa whereDate(). whereDate() genera
 * `date(columna) = ?`, y envolver la columna en una función impide que MySQL,
 * PostgreSQL o SQLite usen el índice: acaban en un full scan de la tabla.
 *
 * En su lugar se traduce cada operador a un rango semiabierto sobre la columna
 * desnuda:
 *
 *     whereDate('created_at', '=', '2026-01-15')
 *     ->  created_at >= '2026-01-15 00:00:00' AND created_at < '2026-01-16 00:00:00'
 *
 * Mismo resultado, pero sargable: el planificador puede hacer un range scan
 * sobre el índice de created_at. En tablas grandes la diferencia es de órdenes
 * de magnitud.
 */
class DateFilterQuery
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
     * Aplica el filtro de fecha de una columna.
     *
     * Lee de $data:
     *   {columna}                -> fecha suelta, combinada con el operador
     *   {columna}_operator       -> operador específico de esta columna
     *   operator                 -> operador compartido (fallback histórico)
     *   {columna}_start_date     -> inicio de rango
     *   {columna}_end_date       -> fin de rango
     *
     * @param Builder<Model> $query
     * @param DataContainer $data
     * @return Builder<Model>
     */
    public static function apply(Builder $query, $data, string $column): Builder
    {
        $qualified = self::qualify($query, $column);

        self::applySingleDate($query, $data, $column, $qualified);
        self::applyRange($query, $data, $column, $qualified);

        return $query;
    }

    /**
     * @param Builder<Model> $query
     */
    protected static function applySingleDate(Builder $query, $data, string $column, string $qualified): void
    {
        $date = self::parse($data, $column);

        if ($date === null) {
            return;
        }

        $operator = self::operator($data, $column);

        if ($operator === null) {
            return;
        }

        $dayStart = self::boundary($date);
        $nextDay = self::boundary($date->copy()->addDay());

        match ($operator) {
            // "después de ese día" empieza en el día siguiente.
            '>' => $query->where($qualified, '>=', $nextDay),
            '>=' => $query->where($qualified, '>=', $dayStart),
            '<' => $query->where($qualified, '<', $dayStart),
            '<=' => $query->where($qualified, '<', $nextDay),
            '=' => $query->where($qualified, '>=', $dayStart)->where($qualified, '<', $nextDay),
            '!=' => $query->where(static function (Builder $sub) use ($qualified, $dayStart, $nextDay): void {
                $sub->where($qualified, '<', $dayStart)->orWhere($qualified, '>=', $nextDay);
            }),
            default => null,
        };
    }

    /**
     * @param Builder<Model> $query
     */
    protected static function applyRange(Builder $query, $data, string $column, string $qualified): void
    {
        $start = self::parse($data, $column.'_start_date');
        $end = self::parse($data, $column.'_end_date');

        if ($start === null && $end === null) {
            return;
        }

        if ($start !== null) {
            $query->where($qualified, '>=', self::boundary($start));
        }

        if ($end !== null) {
            // Intervalo semiabierto: incluye todo el dia final sin depender de
            // la precision de fracciones de segundo de la columna.
            $query->where($qualified, '<', self::boundary($end->copy()->addDay()));
        }
    }

    /**
     * Los limites de un rango de dias se pasan como fecha sin hora.
     *
     * MySQL y PostgreSQL convierten '2026-01-15' a medianoche igual que
     * '2026-01-15 00:00:00', asi que para ellos es indiferente. La diferencia
     * esta en SQLite, que no tiene tipo fecha y compara cadenas: contra una
     * columna DATE que guarda '2026-01-15', la forma larga nunca casaria,
     * porque '2026-01-15' es lexicograficamente menor que '2026-01-15 00:00:00'.
     *
     * La forma corta funciona con columnas DATE y DATETIME en los tres motores.
     */
    protected static function boundary(CarbonInterface $date): string
    {
        return $date->copy()->startOfDay()->format('Y-m-d');
    }

    /**
     * Cualifica la columna con el nombre de la tabla para que no haya
     * ambigüedad si un filtro anterior añadió un join.
     *
     * @param Builder<Model> $query
     */
    protected static function qualify(Builder $query, string $column): string
    {
        if (str_contains($column, '.')) {
            return $column;
        }

        return $query->getModel()->qualifyColumn($column);
    }

    /**
     * El operador de esta columna, o el compartido. null si no es válido.
     */
    protected static function operator($data, string $column): ?string
    {
        $raw = self::read($data, $column.'_operator') ?? self::read($data, 'operator');

        // Sin operador no se aplica nada, igual que en v2. Es deliberado: hay
        // front-ends que mandan la fecha suelta y esperan que no filtre hasta
        // que el usuario elige la comparación.
        if ($raw === null || $raw === '') {
            return null;
        }

        return self::OPERATORS[trim((string) $raw)] ?? null;
    }

    /**
     * @param DataContainer|object $data
     */
    protected static function parse($data, string $key): ?CarbonInterface
    {
        if ($data instanceof DataContainer) {
            return $data->date($key);
        }

        $value = self::read($data, $key);

        if ($value === null || $value === '' || $value === []) {
            return null;
        }

        try {
            return Carbon::parse($value);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @param DataContainer|object $data
     */
    protected static function read($data, string $key): mixed
    {
        if ($data instanceof DataContainer) {
            return $data->get($key);
        }

        return $data->{$key} ?? null;
    }
}
