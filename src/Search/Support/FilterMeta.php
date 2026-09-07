<?php

namespace Innoboxrr\SearchSurge\Search\Support;

use Innoboxrr\SearchSurge\Search\Utils\Managed;

/**
 * Lee las propiedades estáticas opcionales de un filtro.
 *
 * La reflexión se hace una sola vez por clase y por proceso: en un request
 * normal esto es ruido, pero con 25 modelos y 6 filtros cada uno en un worker
 * de Octane la diferencia entre memoizar y no hacerlo es real.
 */
final class FilterMeta
{
    /** @var array<class-string, array<int, string>|null> */
    private static array $keys = [];

    /** @var array<class-string, int> */
    private static array $priority = [];

    /** @var array<class-string, bool> */
    private static array $critical = [];

    /**
     * Las claves de entrada que le interesan al filtro, o null si no declara
     * ninguna (en cuyo caso el filtro se ejecuta siempre).
     *
     * @param class-string $filter
     * @return array<int, string>|null
     */
    public static function keys(string $filter): ?array
    {
        if (array_key_exists($filter, self::$keys)) {
            return self::$keys[$filter];
        }

        $keys = null;

        if (property_exists($filter, 'keys')) {
            $value = self::staticProperty($filter, 'keys');

            if (is_array($value)) {
                $keys = array_values(array_filter(array_map('strval', $value), 'strlen'));

                // Un $keys vacío declarado a propósito no debe apagar el filtro.
                if ($keys === []) {
                    $keys = null;
                }
            }
        }

        return self::$keys[$filter] = $keys;
    }

    /**
     * @param class-string $filter
     */
    public static function priority(string $filter): int
    {
        if (array_key_exists($filter, self::$priority)) {
            return self::$priority[$filter];
        }

        $priority = 0;

        if (property_exists($filter, 'priority')) {
            $value = self::staticProperty($filter, 'priority');

            if (is_numeric($value)) {
                $priority = (int) $value;
            }
        }

        // Los filtros de autorización acotan la consulta antes que nadie salvo
        // que el desarrollador diga explícitamente otra cosa.
        if ($priority === 0 && self::isCritical($filter)) {
            $priority = -100;
        }

        return self::$priority[$filter] = $priority;
    }

    /**
     * Un filtro critico es aquel cuya ausencia cambia el resultado de forma
     * peligrosa, asi que su excepcion se propaga siempre en vez de tragarse.
     *
     * Dos casos:
     *
     *  - Autorizacion (descendientes de Utils\Managed). Seguir sin acotar
     *    seria una fuga de datos.
     *  - Cualquier filtro que declare `public static bool $critical = true`.
     *    Lo hace, por ejemplo, EngineFilter: si el motor de busqueda esta
     *    caido, devolver la tabla entera es peor que devolver un error.
     *
     * @param class-string $filter
     */
    public static function isCritical(string $filter): bool
    {
        return self::$critical[$filter] ??= self::resolveCritical($filter);
    }

    private static function resolveCritical(string $filter): bool
    {
        if (is_subclass_of($filter, Managed::class) || $filter === Managed::class) {
            return true;
        }

        return property_exists($filter, 'critical')
            && self::staticProperty($filter, 'critical') === true;
    }

    /**
     * Olvida lo memoizado. Solo lo necesitan los tests.
     */
    public static function flush(): void
    {
        self::$keys = [];
        self::$priority = [];
        self::$critical = [];
    }

    private static function staticProperty(string $class, string $property): mixed
    {
        try {
            $reflection = new \ReflectionProperty($class, $property);

            if (! $reflection->isStatic() || ! $reflection->isPublic()) {
                return null;
            }

            return $reflection->getValue();
        } catch (\ReflectionException) {
            return null;
        }
    }
}
