<?php

namespace Innoboxrr\SearchSurge\Tests\Unit;

use Illuminate\Contracts\Pagination\Paginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\LazyCollection;
use Innoboxrr\SearchSurge\Tests\Fixtures\Filters\KeyedNameFilter;
use Innoboxrr\SearchSurge\Tests\Models\TaggedModel;
use Innoboxrr\SearchSurge\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

class HasSearchSurgeTest extends TestCase
{
    #[Test]
    public function el_modelo_declara_sus_propios_filtros(): void
    {
        TaggedModel::surgeQuery(['name' => 'ana']);

        $this->assertSame(1, KeyedNameFilter::$calls);
    }

    #[Test]
    public function surgeSearch_ejecuta_la_busqueda(): void
    {
        $result = TaggedModel::surgeSearch(['paginate' => 0]);

        $this->assertInstanceOf(Collection::class, $result);
    }

    #[Test]
    public function surgeOptions_aporta_los_valores_por_defecto_del_modelo(): void
    {
        $result = TaggedModel::surgeSearch(['paginate' => 5]);

        // El modelo declara el paginador 'simple'.
        $this->assertInstanceOf(Paginator::class, $result);
        $this->assertNotInstanceOf(LengthAwarePaginator::class, $result);
    }

    #[Test]
    public function las_opciones_de_la_llamada_ganan_a_las_del_modelo(): void
    {
        $result = TaggedModel::surgeSearch(['paginate' => 5], ['paginator' => 'length_aware']);

        $this->assertInstanceOf(LengthAwarePaginator::class, $result);
    }

    #[Test]
    public function el_scope_se_encadena_sobre_una_consulta_existente(): void
    {
        $sql = TaggedModel::query()
            ->where('owner_id', 4)
            ->surge(['name' => 'ana'])
            ->toSql();

        $this->assertStringContainsString('"owner_id" = ?', $sql);
        $this->assertStringContainsString('"name" like ?', $sql);
    }

    #[Test]
    public function surgeCount_cuenta_aplicando_los_filtros(): void
    {
        TaggedModel::query()->insert([
            ['name' => 'ana'],
            ['name' => 'anacleto'],
            ['name' => 'beto'],
        ]);

        $this->assertSame(2, TaggedModel::surgeCount(['name' => 'ana']));
        $this->assertSame(3, TaggedModel::surgeCount());
    }

    #[Test]
    public function surgeLazy_devuelve_una_coleccion_perezosa(): void
    {
        TaggedModel::query()->insert([['name' => 'ana'], ['name' => 'beto']]);

        $lazy = TaggedModel::surgeLazy();

        $this->assertInstanceOf(LazyCollection::class, $lazy);
        $this->assertSame(2, $lazy->count());
    }
}
