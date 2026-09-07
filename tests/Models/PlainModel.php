<?php

namespace Innoboxrr\SearchSurge\Tests\Models;

use Illuminate\Database\Eloquent\Model;
use Innoboxrr\SearchSurge\Search\Concerns\HasSearchSurge;

/**
 * Usa el trait sin redefinir nada, para ejercitar sus valores por defecto.
 * TaggedModel sobrescribe surgeFilters() y surgeOptions(), asi que por si solo
 * no llega a ejecutarlos.
 */
class PlainModel extends Model
{
    use HasSearchSurge;

    protected $table = 'test_models';

    protected $guarded = [];
}
