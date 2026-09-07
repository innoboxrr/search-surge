<?php

namespace Innoboxrr\SearchSurge\Exceptions;

use Illuminate\Http\JsonResponse;
use RuntimeException;

/**
 * Se pidió una página más profunda de lo que permite la configuración.
 *
 * Paginar con OFFSET obliga al motor a recorrer y descartar todas las filas
 * anteriores, así que el coste de la página N crece linealmente con N. En una
 * tabla grande, servir la página 100.000 es una consulta de varios segundos
 * que además no le sirve a nadie: nadie navega hasta ahí a mano.
 *
 * Cuando se llega a este punto, la respuesta correcta no es paginar más hondo,
 * sino filtrar más o cambiar a paginación por cursor.
 */
class PageLimitExceededException extends RuntimeException
{
    public function __construct(
        public readonly int $page,
        public readonly int $maxPage,
    ) {
        parent::__construct(
            "SearchSurge: se pidió la página {$page}, por encima del máximo de {$maxPage}. "
            .'Afina los filtros o usa paginación por cursor.'
        );
    }

    /**
     * Se renderiza como 400: es un problema de la petición, no del servidor.
     */
    public function getStatusCode(): int
    {
        return 400;
    }

    public function render(): JsonResponse
    {
        return response()->json([
            'message' => $this->getMessage(),
            'page' => $this->page,
            'max_page' => $this->maxPage,
        ], 400);
    }
}
