<?php

namespace Innoboxrr\SearchSurge\Tests\Fixtures\Engine;

class SmallLimitEngineFilter extends FakeEngineFilter
{
    protected static function limit(): int
    {
        return 3;
    }

    protected static function minLength(): int
    {
        return 3;
    }
}
