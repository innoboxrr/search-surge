<?php

namespace Innoboxrr\SearchSurge\Search\Filters;

use Illuminate\Database\Eloquent\Builder;
use Innoboxrr\SearchSurge\Search\Support\DataContainer;
use Innoboxrr\SearchSurge\Search\Utils\Relevance;

/**
 * Base para delegar la búsqueda de texto en un motor externo.
 *
 * La idea: el motor hace lo que sabe hacer (relevancia, erratas, sinónimos) y
 * devuelve una lista de ids ordenada. SQL hace lo que el motor hace mal
 * (filtros exactos, joins y, sobre todo, **autorización**).
 *
 *     Elastic/Algolia  ->  [42, 17, 99, ...]
 *              ↓
 *     WHERE id IN (...) AND owner_id = ? AND status = ?  ORDER BY FIELD(id, ...)
 *
 * Esto es lo que hace que el híbrido sea mejor que cualquiera de los dos por
 * separado: los permisos nunca salen de la base de datos, así que no hay que
 * desnormalizarlos al índice ni reindexar cuando cambian.
 *
 * Medido en MySQL 8 sobre 1.000.000 de filas, la parte SQL cuesta 0,6 ms con 20
 * ids, 1,6 ms con 200 y 5,8 ms con 1.000. Frente a los 26.912 ms de un
 * LIKE '%...%' sobre la misma tabla.
 *
 * Uso mínimo, con Laravel Scout (Algolia, Meilisearch, Typesense y
 * Elasticsearch vía driver):
 *
 *     class SearchFilter extends EngineFilter
 *     {
 *         // Nada más. El modelo sale de la propia consulta.
 *     }
 *
 * Con un cliente propio, sin Scout:
 *
 *     class SearchFilter extends EngineFilter
 *     {
 *         protected static function ids(Builder $query, DataContainer $data, string $term): array
 *         {
 *             return app(MiClienteElastic::class)->buscarIds($term, static::limit());
 *         }
 *     }
 */
abstract class EngineFilter
{
    /**
     * Solo se ejecuta si viene el término. Redefínelo si cambias searchKey().
     *
     * @var array<int, string>
     */
    public static array $keys = ['q'];

    /**
     * Se aplica pronto: acotar por ids reduce el trabajo de todo lo que venga
     * después. Solo los filtros de autorización van antes.
     */
    public static int $priority = -50;

    /**
     * Si el motor esta caido, la busqueda debe fallar, no devolver la tabla
     * entera. Un `?q=zapato` que ignora el termino y responde 1.000.000 de
     * filas es peor que un error: parece que funciona.
     */
    public static bool $critical = true;

    /**
     * @param Builder<\Illuminate\Database\Eloquent\Model> $query
     * @return Builder<\Illuminate\Database\Eloquent\Model>
     */
    public static function apply(Builder $query, DataContainer $data): Builder
    {
        $term = trim($data->string(static::searchKey()));

        if (mb_strlen($term) < static::minLength()) {
            return $query;
        }

        $ids = array_values(static::ids($query, $data, $term));

        // Sin resultados hay que devolver cero filas, no todas: whereIn con un
        // array vacio genera `0 = 1`, que es exactamente eso.
        if ($ids === []) {
            return $query->whereIn($query->getModel()->getQualifiedKeyName(), []);
        }

        return static::ordersByRelevance()
            ? Relevance::constrain($query, $ids)
            : $query->whereIn($query->getModel()->getQualifiedKeyName(), $ids);
    }

    /* -----------------------------------------------------------------
     | Puntos de extensión
     | ----------------------------------------------------------------- */

    /**
     * Los ids que devuelve el motor, en orden de relevancia.
     *
     * La implementación por defecto usa Laravel Scout si el modelo es
     * `Searchable`. Scout es una dependencia opcional: el paquete no la
     * requiere, solo la usa si está.
     *
     * @param Builder<\Illuminate\Database\Eloquent\Model> $query
     * @return array<int, int|string>
     */
    protected static function ids(Builder $query, DataContainer $data, string $term): array
    {
        $model = $query->getModel();

        if (! method_exists($model, 'search')) {
            throw new \RuntimeException(
                'SearchSurge: ' . $model::class . ' no es buscable. Instala laravel/scout y anade el '
                . 'trait Searchable al modelo, o sobrescribe ids() en ' . static::class . '.'
            );
        }

        return $model::search($term)
            ->take(static::limit())
            ->keys()
            ->all();
    }

    /**
     * Clave de la que se lee el término.
     */
    protected static function searchKey(): string
    {
        return 'q';
    }

    /**
     * Cuántos ids como máximo se le piden al motor.
     *
     * Es el parámetro que decide la forma del híbrido y merece una decisión
     * consciente:
     *
     *  - Con un tope bajo, el motor pagina de hecho y los filtros SQL solo
     *    recortan: rápido, pero los totales pueden quedarse cortos si SQL
     *    descarta muchas filas del conjunto.
     *  - Con un tope alto, SQL manda y los totales son exactos, pero el
     *    `IN (...)` crece: 5.000 ids ya son 34 ms.
     */
    protected static function limit(): int
    {
        return 500;
    }

    /**
     * Longitud mínima del término antes de molestar al motor.
     */
    protected static function minLength(): int
    {
        return 1;
    }

    /**
     * Si el orden del motor debe imponerse sobre el de la consulta.
     *
     * Devuelve false cuando quieras que mande el `orderBy` del usuario y el
     * motor solo actúe como filtro.
     */
    protected static function ordersByRelevance(): bool
    {
        return true;
    }
}
