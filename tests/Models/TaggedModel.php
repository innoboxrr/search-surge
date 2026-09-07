<?php

namespace Innoboxrr\SearchSurge\Tests\Models;

use Illuminate\Database\Eloquent\Model;
use Innoboxrr\SearchSurge\Search\Concerns\HasSearchSurge;
use Innoboxrr\SearchSurge\Tests\Fixtures\Filters\KeyedNameFilter;

/**
 * Modelo que declara sus filtros a mano: ni convención, ni disco.
 */
class TaggedModel extends Model
{
    use HasSearchSurge;

    protected $table = 'test_models';

    protected $guarded = [];

    public static function surgeFilters(): array
    {
        return [KeyedNameFilter::class];
    }

    public static function surgeOptions(): array
    {
        return ['paginator' => 'simple'];
    }
}
