<?php

namespace Innoboxrr\SearchSurge\Tests\Models;

use Illuminate\Database\Eloquent\Model;
use Laravel\Scout\Searchable;

/**
 * Modelo buscable con Laravel Scout.
 *
 * Existe para ejercitar el camino por defecto de EngineFilter::ids(), que llama
 * a `$model::search()`. Sin un modelo Searchable de verdad, de ese metodo solo
 * se probaba la rama de error.
 *
 * Con el driver `collection` de Scout, la busqueda la resuelve el propio
 * Eloquent: no hace falta levantar Elasticsearch ni Algolia para comprobar que
 * el hibrido encaja.
 */
class SearchableModel extends Model
{
    use Searchable;

    protected $table = 'test_models';

    protected $guarded = [];

    public function searchableAs(): string
    {
        return 'test_models_index';
    }

    /**
     * @return array<string, mixed>
     */
    public function toSearchableArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
        ];
    }
}
