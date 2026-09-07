<?php

namespace Innoboxrr\SearchSurge\Search\Support;

/**
 * El contrato de entrada de una búsqueda, derivado de los propios filtros.
 *
 * La entrada dispersa —mandas solo los parámetros que te interesan— es el punto
 * fuerte del paquete, pero tiene una consecuencia incómoda: un `?nombre=x` en
 * vez de `?name=x` no falla, simplemente no filtra. Y no hay forma de preguntar
 * qué acepta un endpoint.
 *
 * Las `$keys` que declara cada filtro resuelven eso sin añadir nada nuevo: ya
 * son la lista de parámetros que ese filtro entiende. Esta clase las junta y las
 * convierte en algo publicable: documentación, un esquema para el front-end o
 * una validación estricta.
 */
class SearchSchema
{
    /**
     * Parámetros que interpreta el propio Builder, comunes a todos los modelos.
     */
    public const CONTROL_PARAMETERS = [
        'paginate' => 'Tamano de pagina. 0 devuelve todo.',
        'page' => 'Pagina actual.',
        'cursor' => 'Cursor de la pagina, con el paginador por cursor.',
        'paginator' => 'length_aware | simple | cursor.',
        'orderBy' => 'Columna por la que ordenar.',
        'orderMode' => 'asc | desc.',
    ];

    public function __construct(protected FilterRegistry $registry) {}

    /**
     * El esquema completo de un modelo.
     *
     * @param class-string $model
     * @param array<string, mixed> $options
     * @return array{
     *     model: string,
     *     filters: array<int, array{class: string, name: string, parameters: array<int, string>|null, priority: int, critical: bool}>,
     *     parameters: array<int, string>,
     *     control: array<string, string>,
     *     complete: bool
     * }
     */
    public function for(string $model, array $options = []): array
    {
        $filters = $this->registry->resolve($model, $options);
        $parameters = [];
        $described = [];
        $complete = true;

        foreach ($filters as $filter) {
            $keys = FilterMeta::keys($filter);

            // Un filtro sin $keys puede leer cualquier cosa: mientras haya uno,
            // la lista de parametros no se puede dar por cerrada.
            if ($keys === null) {
                $complete = false;
            } else {
                $parameters = array_merge($parameters, $keys);
            }

            $described[] = [
                'class' => $filter,
                'name' => class_basename($filter),
                'parameters' => $keys,
                'priority' => FilterMeta::priority($filter),
                'critical' => FilterMeta::isCritical($filter),
            ];
        }

        $parameters = array_values(array_unique($parameters));
        sort($parameters, SORT_STRING);

        return [
            'model' => $model,
            'filters' => $described,
            'parameters' => $parameters,
            'control' => self::CONTROL_PARAMETERS,
            'complete' => $complete,
        ];
    }

    /**
     * Todos los parámetros aceptados, de filtros y de control.
     *
     * @param class-string $model
     * @param array<string, mixed> $options
     * @return array<int, string>
     */
    public function parameters(string $model, array $options = []): array
    {
        $schema = $this->for($model, $options);

        $all = array_merge($schema['parameters'], array_keys(self::CONTROL_PARAMETERS));
        $all = array_values(array_unique($all));
        sort($all, SORT_STRING);

        return $all;
    }

    /**
     * Los parámetros de la petición que ningún filtro va a leer.
     *
     * Devuelve una lista vacía si algún filtro no declara `$keys`: en ese caso
     * no se puede afirmar que sobren, y decir lo contrario sería mentir.
     *
     * @param class-string $model
     * @param array<string, mixed> $data
     * @param array<string, mixed> $options
     * @return array<int, string>
     */
    public function unknown(string $model, array $data, array $options = []): array
    {
        $schema = $this->for($model, $options);

        if (! $schema['complete']) {
            return [];
        }

        $known = array_merge($schema['parameters'], array_keys(self::CONTROL_PARAMETERS));

        return array_values(array_diff(array_keys($data), $known));
    }
}
