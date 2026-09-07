<?php

namespace Innoboxrr\SearchSurge\Search\Filters\Common;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Innoboxrr\SearchSurge\Search\Contracts\Filter;
use Innoboxrr\SearchSurge\Search\Support\DataContainer;

/**
 * Incluye o aisla los registros borrados logicamente.
 *
 *     ?trashed=with   ->  los vivos y los borrados
 *     ?trashed=only   ->  solo los borrados
 *     (sin el)        ->  solo los vivos, como siempre
 *
 * Solo hace algo si el modelo usa SoftDeletes. Sin esa comprobacion, llamar a
 * withTrashed() sobre un modelo que no lo usa revienta.
 *
 * El valor se compara contra una lista cerrada: cualquier otra cosa no cambia
 * nada, asi que un ?trashed=cualquiercosa nunca expone borrados por accidente.
 */
class SoftDeletesFilter implements Filter
{
    /** @var array<int, string> */
    public static array $keys = ['trashed'];

    /**
     * @param Builder<Model> $query
     * @return Builder<Model>
     */
    public static function apply(Builder $query, DataContainer $data): Builder
    {
        if (! self::usesSoftDeletes($query)) {
            return $query;
        }

        return match (strtolower($data->string('trashed'))) {
            'with', 'all' => $query->withTrashed(),
            'only' => $query->onlyTrashed(),
            default => $query,
        };
    }

    /**
     * @param Builder<Model> $query
     */
    protected static function usesSoftDeletes(Builder $query): bool
    {
        return in_array(SoftDeletes::class, class_uses_recursive($query->getModel()), true);
    }
}
