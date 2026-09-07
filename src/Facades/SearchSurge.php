<?php

namespace Innoboxrr\SearchSurge\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @method static \Illuminate\Database\Eloquent\Builder query(string $model, array $data = [], array $options = [])
 * @method static mixed get(string $model, array $data = [], array $options = [])
 * @method static int count(string $model, array $data = [], array $options = [])
 * @method static bool exists(string $model, array $data = [], array $options = [])
 * @method static \Illuminate\Database\Eloquent\Model|null first(string $model, array $data = [], array $options = [])
 * @method static \Illuminate\Support\LazyCollection lazy(string $model, array $data = [], array $options = [], int $chunkSize = 1000)
 * @method static \Illuminate\Support\LazyCollection lazyById(string $model, array $data = [], array $options = [], int $chunkSize = 1000)
 * @method static \Illuminate\Support\LazyCollection cursor(string $model, array $data = [], array $options = [])
 * @method static \Innoboxrr\SearchSurge\Search\Builder setOptions(array $options = [])
 * @method static \Innoboxrr\SearchSurge\Search\Builder setBasePath(string $basePath)
 *
 * @see \Innoboxrr\SearchSurge\Search\Builder
 */
class SearchSurge extends Facade
{
    /**
     * El facade entrega una instancia nueva en cada llamada. El Builder guarda
     * el estado de la búsqueda en curso, y cachear la instancia haría que dos
     * búsquedas anidadas se pisaran.
     */
    protected static $cached = false;

    protected static function getFacadeAccessor(): string
    {
        return 'search-surge';
    }

    /* -----------------------------------------------------------------
     | Registro de filtros
     |
     | Atajos al FilterRegistry, que es lo que un paquete usa en su
     | ServiceProvider para decirle a SearchSurge dónde están sus filtros.
     | ----------------------------------------------------------------- */

    /**
     * @param class-string $model
     * @param array<int, class-string> $filters
     */
    public static function registerFilters(string $model, array $filters): void
    {
        static::registry()->register($model, $filters);
    }

    /**
     * @param array<class-string, array<int, class-string>> $map
     */
    public static function registerFilterMap(array $map): void
    {
        static::registry()->registerMany($map);
    }

    /**
     * Le dice a SearchSurge que los modelos de $modelNamespace tienen sus
     * filtros en $filtersNamespace. Una línea en el ServiceProvider del paquete
     * y se acabaron las rutas 'vendor/...' a mano.
     */
    public static function registerNamespace(string $modelNamespace, string $filtersNamespace): void
    {
        static::registry()->registerNamespace($modelNamespace, $filtersNamespace);
    }

    /**
     * @param class-string|null $model
     */
    public static function forgetFilters(?string $model = null): void
    {
        static::registry()->forget($model);
    }

    /**
     * Los filtros que se aplicarían a un modelo. Útil para depurar por qué un
     * filtro no se está ejecutando.
     *
     * @param class-string $model
     * @param array<string, mixed> $options
     * @return array<int, class-string>
     */
    public static function filtersFor(string $model, array $options = []): array
    {
        return static::registry()->resolve($model, $options);
    }

    /**
     * El contrato de entrada de un modelo, derivado de las $keys de sus
     * filtros: que parametros acepta, cuales son de control y si la lista se
     * puede dar por cerrada.
     *
     * @param class-string $model
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    public static function schema(string $model, array $options = []): array
    {
        return static::schemaBuilder()->for($model, $options);
    }

    /**
     * Los parametros de $data que ningun filtro va a leer. Vacio si algun
     * filtro no declara $keys, porque entonces no se puede afirmar que sobren.
     *
     * @param class-string $model
     * @param array<string, mixed> $data
     * @param array<string, mixed> $options
     * @return array<int, string>
     */
    public static function unknownParameters(string $model, array $data, array $options = []): array
    {
        return static::schemaBuilder()->unknown($model, $data, $options);
    }

    public static function schemaBuilder(): \Innoboxrr\SearchSurge\Search\Support\SearchSchema
    {
        return static::getFacadeApplication()->make(
            \Innoboxrr\SearchSurge\Search\Support\SearchSchema::class
        );
    }

    public static function registry(): \Innoboxrr\SearchSurge\Search\Support\FilterRegistry
    {
        return static::getFacadeApplication()->make(
            \Innoboxrr\SearchSurge\Search\Support\FilterRegistry::class
        );
    }
}
