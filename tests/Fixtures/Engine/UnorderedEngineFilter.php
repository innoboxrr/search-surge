<?php

namespace Innoboxrr\SearchSurge\Tests\Fixtures\Engine;

/**
 * Variante que usa el motor solo como filtro y deja mandar al orden del
 * usuario.
 */
class UnorderedEngineFilter extends FakeEngineFilter
{
    protected static function ordersByRelevance(): bool
    {
        return false;
    }
}
