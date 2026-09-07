<?php

namespace Innoboxrr\SearchSurge\Tests\Fixtures\Engine;

/**
 * Igual que ScoutEngineFilter pero con un tope bajo, para comprobar que el
 * limite llega de verdad al motor y no solo se declara.
 */
class LimitedScoutFilter extends ScoutEngineFilter
{
    protected static function limit(): int
    {
        return 2;
    }
}
