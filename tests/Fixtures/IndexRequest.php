<?php

namespace Innoboxrr\SearchSurge\Tests\Fixtures;

use Illuminate\Foundation\Http\FormRequest;
use Innoboxrr\SearchSurge\Facades\SearchSurge;
use Innoboxrr\SearchSurge\Tests\Models\TestModel;

/**
 * Réplica del patrón que usan los paquetes consumidores: un FormRequest que
 * delega en SearchSurge con $this->all() y sin opciones.
 */
class IndexRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [];
    }

    public function handle()
    {
        return SearchSurge::get(TestModel::class, $this->all());
    }
}
