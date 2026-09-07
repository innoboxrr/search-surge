<?php

namespace Innoboxrr\SearchSurge\Tests\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Comparte tabla con TestModel pero con borrado logico y una relacion, para
 * probar los filtros comunes sin ensuciar el modelo principal.
 */
class SoftModel extends Model
{
    use SoftDeletes;

    protected $table = 'test_models';

    protected $guarded = [];

    public function author(): BelongsTo
    {
        return $this->belongsTo(TestAuthor::class, 'author_id');
    }
}
