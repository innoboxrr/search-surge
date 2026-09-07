<?php

namespace Innoboxrr\SearchSurge\Tests\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TestAuthor extends Model
{
    protected $table = 'test_authors';

    protected $guarded = [];

    public $timestamps = false;

    public function posts(): HasMany
    {
        return $this->hasMany(SoftModel::class, 'author_id');
    }
}
