<?php

namespace Innoboxrr\SearchSurge\Search\Concerns;

use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Innoboxrr\SearchSurge\Search\Builder;

/**
 * Azúcar sintáctico para buscar desde el propio modelo.
 *
 *     class Blog extends Model
 *     {
 *         use HasSearchSurge;
 *     }
 *
 *     Blog::surge($request->all());                    // ejecuta
 *     Blog::surgeQuery($request->all());               // solo construye
 *     Blog::where('active', 1)->surge($request->all()); // compone
 *
 * El nombre no es `search()` a propósito: eso chocaría con Laravel Scout.
 *
 * @method static \Illuminate\Database\Eloquent\Builder surge(array $data = [], array $options = [])
 */
trait HasSearchSurge
{
    /**
     * Sobrescríbelo para declarar los filtros a mano y saltarte por completo el
     * descubrimiento por convención.
     *
     * @return array<int, class-string>
     */
    public static function surgeFilters(): array
    {
        return [];
    }

    /**
     * Opciones por defecto de este modelo (columnas, relaciones, paginador...).
     *
     * @return array<string, mixed>
     */
    public static function surgeOptions(): array
    {
        return [];
    }

    /**
     * Scope local, para encadenar sobre una consulta ya empezada.
     *
     * @param EloquentBuilder<static> $query
     * @param array<string, mixed> $data
     * @param array<string, mixed> $options
     * @return EloquentBuilder<static>
     */
    public function scopeSurge(EloquentBuilder $query, array $data = [], array $options = []): EloquentBuilder
    {
        return static::surgeBuilder()->query(static::class, $data, array_merge(
            static::mergedSurgeOptions($options),
            ['query' => $query],
        ));
    }

    /**
     * Construye la consulta filtrada sin ejecutarla.
     *
     * @param array<string, mixed> $data
     * @param array<string, mixed> $options
     * @return EloquentBuilder<static>
     */
    public static function surgeQuery(array $data = [], array $options = []): EloquentBuilder
    {
        return static::surgeBuilder()->query(static::class, $data, static::mergedSurgeOptions($options));
    }

    /**
     * Ejecuta la búsqueda.
     *
     * @param array<string, mixed> $data
     * @param array<string, mixed> $options
     * @return \Illuminate\Contracts\Pagination\Paginator|\Illuminate\Contracts\Pagination\CursorPaginator|\Illuminate\Database\Eloquent\Collection
     */
    public static function surgeSearch(array $data = [], array $options = [])
    {
        return static::surgeBuilder()->get(static::class, $data, static::mergedSurgeOptions($options));
    }

    /**
     * Recorre los resultados sin cargarlos todos en memoria. Es lo que quieres
     * en un export.
     *
     * @param array<string, mixed> $data
     * @param array<string, mixed> $options
     * @return \Illuminate\Support\LazyCollection<int, static>
     */
    public static function surgeLazy(array $data = [], array $options = [], int $chunkSize = 1000)
    {
        return static::surgeBuilder()->lazy(static::class, $data, static::mergedSurgeOptions($options), $chunkSize);
    }

    /**
     * @param array<string, mixed> $data
     * @param array<string, mixed> $options
     */
    public static function surgeCount(array $data = [], array $options = []): int
    {
        return static::surgeBuilder()->count(static::class, $data, static::mergedSurgeOptions($options));
    }

    /**
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    protected static function mergedSurgeOptions(array $options): array
    {
        return array_merge(static::surgeOptions(), $options);
    }

    protected static function surgeBuilder(): Builder
    {
        return app(Builder::class);
    }
}
