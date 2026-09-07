<?php

namespace Innoboxrr\SearchSurge\Search\Utils;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Innoboxrr\SearchSurge\Search\Support\DataContainer;

/**
 * Acota la consulta a lo que el usuario autenticado puede administrar.
 *
 * Extiéndelo desde el ManagedFilter de tu modelo e implementa canView():
 *
 *     class ManagedFilter extends Managed
 *     {
 *         public static function canView($query, $user, array $args = [])
 *         {
 *             return method_exists($user, 'managedBlogFilter')
 *                 ? $user->managedBlogFilter($query, $args)
 *                 : $query;
 *         }
 *     }
 *
 * SearchSurge trata a los descendientes de esta clase como filtros críticos:
 * se aplican los primeros y, si lanzan, la excepción se propaga en vez de
 * tragarse. Devolver resultados sin acotar porque el filtro de permisos falló
 * sería una fuga de datos.
 *
 * Entrada:
 *   managed         -> aplicar la restricción (booleano)
 *   except_view_any -> saltarla si el usuario tiene el permiso viewAny
 */
class Managed
{
    /**
     * Los filtros de autorización siempre corren, vengan o no estas claves,
     * así que a propósito NO se declara $keys aquí.
     */
    public static int $priority = -100;

    /**
     * Sin tipo de retorno declarado a propósito: los ManagedFilter de terceros
     * heredan o sobrescriben este método, y añadirlo aquí haría fatal cualquier
     * override que no lo repitiera.
     *
     * @param Builder<Model> $query
     * @return Builder<Model>
     */
    public static function apply(Builder $query, $data)
    {
        if (! static::boolean($data, 'managed')) {
            return $query;
        }

        $user = auth()->user();

        if ($user === null) {
            return $query;
        }

        // Con except_view_any, quien tenga el permiso global ve todo y no se
        // le aplica la restricción.
        if (static::boolean($data, 'except_view_any')) {
            $model = static::modelClassName($data);

            if ($model !== null && $user->can('viewAny', $model)) {
                return $query;
            }
        }

        return static::canViewConstraint($query, $user, $data);
    }

    /**
     * @param Builder<Model> $query
     * @return Builder<Model>
     */
    public static function canViewConstraint($query, $user, $data)
    {
        $filter = static::read($data, 'managedFilterClass');

        if (! is_string($filter) || ! class_exists($filter) || ! method_exists($filter, 'canView')) {
            return $query;
        }

        $args = $data instanceof DataContainer ? $data->all() : (array) $data;

        return $filter::canView($query, $user, $args) ?? $query;
    }

    /**
     * Booleano tolerante con lo que llega por query string.
     *
     * Aquí estaba el bug histórico: `$data->managed == true` era verdadero para
     * la cadena "false", porque en PHP toda cadena no vacía distinta de "0" es
     * truthy. Con ?except_view_any=false se acababa saltando la restricción de
     * permisos justo cuando se pedía lo contrario.
     */
    protected static function boolean($data, string $key): bool
    {
        if ($data instanceof DataContainer) {
            return $data->boolean($key);
        }

        return filter_var($data->{$key} ?? false, FILTER_VALIDATE_BOOLEAN);
    }

    /**
     * @return class-string|null
     */
    protected static function modelClassName($data): ?string
    {
        $name = static::read($data, 'modelClassName');

        if (is_string($name) && $name !== '') {
            return $name;
        }

        $instance = static::read($data, 'modelClass');

        return is_object($instance) ? $instance::class : null;
    }

    protected static function read($data, string $key): mixed
    {
        if ($data instanceof DataContainer) {
            return $data->get($key);
        }

        return $data->{$key} ?? null;
    }
}
