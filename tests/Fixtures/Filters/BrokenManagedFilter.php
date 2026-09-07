<?php

namespace Innoboxrr\SearchSurge\Tests\Fixtures\Filters;

use Illuminate\Database\Eloquent\Builder;
use Innoboxrr\SearchSurge\Search\Utils\Managed;

/**
 * Filtro de autorizacion que revienta. Su excepcion NUNCA debe tragarse:
 * degradar a "devuelve todo" seria una fuga de datos.
 */
class BrokenManagedFilter extends Managed
{
    public static function apply(Builder $query, $data): Builder
    {
        throw new \RuntimeException('filtro de permisos roto');
    }
}
