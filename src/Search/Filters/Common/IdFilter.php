<?php

namespace Innoboxrr\SearchSurge\Search\Filters\Common;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Innoboxrr\SearchSurge\Search\Contracts\Filter;
use Innoboxrr\SearchSurge\Search\Support\DataContainer;
use Innoboxrr\SearchSurge\Search\Utils\Order;

/**
 * Filtro por clave primaria, válido para cualquier modelo.
 *
 * No asume que la clave se llame `id`: la lee del propio modelo, así que
 * funciona igual con `uuid`, `codigo` o lo que hayas puesto en $primaryKey.
 *
 *     ?id=7
 *     ?ids=1,2,3
 *     ?id_not=4,5
 *     ?orderBy=id&orderMode=desc
 *
 * Es uno de los filtros que SearchSurge añade solo, sin que crees ningún
 * archivo. Si tu modelo ya tiene su propio IdFilter, este no se aplica.
 */
class IdFilter implements Filter
{
    /** @var array<int, string> */
    public static array $keys = ['id', 'ids', 'id_not', ...Order::KEYS];

    /**
     * @param Builder<Model> $query
     * @return Builder<Model>
     */
    public static function apply(Builder $query, DataContainer $data): Builder
    {
        $model = $query->getModel();
        $key = $model->getKeyName();
        $qualified = $model->getQualifiedKeyName();

        if ($data->filled('id')) {
            $query->where($qualified, self::cast($model, $data->get('id')));
        }

        if ($data->filled('ids')) {
            $query->whereIn($qualified, array_map(
                static fn ($value) => self::cast($model, $value),
                $data->array('ids')
            ));
        }

        if ($data->filled('id_not')) {
            $query->whereNotIn($qualified, array_map(
                static fn ($value) => self::cast($model, $value),
                $data->array('id_not')
            ));
        }

        return Order::orderBy($query, $data, $key);
    }

    /**
     * Castea segun el tipo de clave del modelo.
     *
     * Con claves autoincrementales se fuerza a entero, para que un ?id=abc no
     * acabe comparando cadenas. Con uuid o ulid se deja tal cual.
     */
    protected static function cast(Model $model, mixed $value): mixed
    {
        if (! $model->getIncrementing()) {
            return $value;
        }

        return is_numeric($value) ? (int) $value : 0;
    }
}
