<?php

namespace Innoboxrr\SearchSurge\Tests\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Modelo que se apoya en el descubrimiento por convención:
 * Innoboxrr\SearchSurge\Tests\Models\TestModel
 *   -> Innoboxrr\SearchSurge\Tests\Models\Filters\TestModel\*
 */
class TestModel extends Model
{
    protected $table = 'test_models';

    protected $guarded = [];
}
