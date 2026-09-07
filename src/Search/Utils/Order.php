<?php

namespace Innoboxrr\SearchSurge\Search\Utils;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Innoboxrr\SearchSurge\Search\Support\DataContainer;

/**
 * Ordenamiento seguro.
 *
 * El patrón es que cada filtro se declare responsable de una columna:
 *
 *     Order::orderBy($query, $data, 'created_at');
 *
 * Como la columna la pone el filtro y no la petición, no hay forma de inyectar
 * SQL a través de ?orderBy=. La petición solo elige *cuál* de las columnas ya
 * declaradas se usa.
 *
 * La columna se pasa tal cual al ORDER BY, sin cualificar con el nombre de la
 * tabla, porque puede ser un alias de un select computado. Si tu filtro hace
 * joins y la columna es ambigua, pasa 'tabla.columna'.
 *
 * Datos de entrada:
 *   orderBy   -> nombre de columna
 *   orderMode -> 'asc' | 'desc'
 */
class Order
{
    /**
     * @var array<int, string>
     */
    public const KEYS = ['orderBy', 'orderMode'];

    /**
     * Ordena por $column si la petición pidió justamente esa columna.
     *
     * @param Builder<Model> $query
     * @return Builder<Model>
     */
    public static function orderBy(Builder $query, $data, string $column): Builder
    {
        if (self::read($data, 'orderBy') !== $column) {
            return $query;
        }

        return $query->orderBy($column, self::direction($data));
    }

    /**
     * Ordena por varias columnas de golpe cuando la petición manda una lista
     * ("name,-created_at"), respetando una lista blanca.
     *
     * El prefijo '-' invierte el sentido de esa columna.
     *
     * @param Builder<Model> $query
     * @param array<int, string> $allowed
     * @return Builder<Model>
     */
    public static function orderByAny(Builder $query, $data, array $allowed): Builder
    {
        $raw = self::read($data, 'orderBy');

        if (! is_string($raw) || $raw === '' || $allowed === []) {
            return $query;
        }

        $default = self::direction($data);

        foreach (explode(',', $raw) as $piece) {
            $piece = trim($piece);

            if ($piece === '') {
                continue;
            }

            $direction = $default;

            if (str_starts_with($piece, '-')) {
                $piece = substr($piece, 1);
                $direction = 'desc';
            } elseif (str_starts_with($piece, '+')) {
                $piece = substr($piece, 1);
                $direction = 'asc';
            }

            // Solo columnas explícitamente permitidas llegan al SQL.
            if (in_array($piece, $allowed, true)) {
                $query->orderBy($piece, $direction);
            }
        }

        return $query;
    }

    /**
     * Orden por defecto, aplicado solo si nadie pidió otro.
     *
     * Un ORDER BY estable importa más de lo que parece: sin él, la paginación
     * puede repetir o saltarse filas entre páginas.
     *
     * @param Builder<Model> $query
     * @return Builder<Model>
     */
    public static function fallback(Builder $query, $data, string $column, string $direction = 'desc'): Builder
    {
        $requested = self::read($data, 'orderBy');

        if (is_string($requested) && $requested !== '') {
            return $query;
        }

        if (! empty($query->getQuery()->orders)) {
            return $query;
        }

        return $query->orderBy($column, strtolower($direction) === 'asc' ? 'asc' : 'desc');
    }

    protected static function direction($data): string
    {
        return strtolower((string) self::read($data, 'orderMode')) === 'desc' ? 'desc' : 'asc';
    }

    protected static function read($data, string $key): mixed
    {
        if ($data instanceof DataContainer) {
            return $data->get($key);
        }

        return $data->{$key} ?? null;
    }
}
