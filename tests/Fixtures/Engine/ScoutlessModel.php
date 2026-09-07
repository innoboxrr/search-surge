<?php

namespace Innoboxrr\SearchSurge\Tests\Fixtures\Engine;

use Illuminate\Database\Eloquent\Model;

/**
 * Modelo sin Scout: EngineFilter debe explicarlo, no fallar con un error raro.
 */
class ScoutlessModel extends Model
{
    protected $table = 'test_models';

    protected $guarded = [];
}
