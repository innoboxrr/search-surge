<?php

namespace Innoboxrr\SearchSurge\Search\Support;

use Illuminate\Database\Connection;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Qué motor hay detrás de una consulta.
 *
 * Existe porque `$query->getConnection()` devuelve `ConnectionInterface`, y esa
 * interfaz **no** declara `getDriverName()`. Con las conexiones de Laravel
 * funciona porque la clase concreta sí lo tiene, pero cualquier conexión
 * personalizada que implemente solo el contrato haría fatal.
 *
 * Varias piezas del paquete adaptan el SQL al motor (ILIKE en PostgreSQL,
 * FIELD() en MySQL, la sintaxis del EXPLAIN), así que la comprobación estaba
 * repetida en cuatro sitios. Aquí se hace una vez y se degrada a cadena vacía,
 * que en todas ellas significa "usa la variante portable".
 */
final class Driver
{
    /**
     * @param Builder<Model> $query
     */
    public static function of(Builder $query): string
    {
        return self::ofConnection($query->getConnection());
    }

    public static function ofConnection(mixed $connection): string
    {
        return $connection instanceof Connection
            ? (string) $connection->getDriverName()
            : '';
    }

    /**
     * @param Builder<Model> $query
     * @param array<int, string> $drivers
     */
    public static function is(Builder $query, array $drivers): bool
    {
        return in_array(self::of($query), $drivers, true);
    }
}
