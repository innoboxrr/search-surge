<?php

namespace Innoboxrr\SearchSurge\Tests\Unit;

use Innoboxrr\SearchSurge\Search\Builder;
use Innoboxrr\SearchSurge\Search\Filters\EngineFilter;
use Innoboxrr\SearchSurge\Search\Support\FilterMeta;
use Innoboxrr\SearchSurge\Search\Utils\Relevance;
use Innoboxrr\SearchSurge\Tests\Fixtures\Engine\DefaultEngineFilter;
use Innoboxrr\SearchSurge\Tests\Fixtures\Engine\FakeEngine;
use Innoboxrr\SearchSurge\Tests\Fixtures\Engine\FakeEngineFilter;
use Innoboxrr\SearchSurge\Tests\Fixtures\Engine\SmallLimitEngineFilter;
use Innoboxrr\SearchSurge\Tests\Fixtures\Engine\UnorderedEngineFilter;
use Innoboxrr\SearchSurge\Tests\Fixtures\Filters\BrokenFilter;
use Innoboxrr\SearchSurge\Tests\Models\Filters\TestModel\IdFilter;
use Innoboxrr\SearchSurge\Tests\Models\Filters\TestModel\ManagedFilter;
use Innoboxrr\SearchSurge\Tests\Models\TestModel;
use Innoboxrr\SearchSurge\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * El patrón híbrido: el motor externo pone la relevancia, SQL pone los filtros
 * exactos y la autorización.
 */
class EngineFilterTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        FakeEngine::reset();
    }

    protected function builder(): Builder
    {
        return $this->app->make(Builder::class);
    }

    protected function seedRows(int $count = 10): void
    {
        TestModel::query()->insert(array_map(
            static fn (int $i): array => [
                'name' => 'fila-'.$i,
                'owner_id' => $i % 2,
            ],
            range(1, $count)
        ));
    }

    /* -----------------------------------------------------------------
     | Relevance
     | ----------------------------------------------------------------- */

    #[Test]
    public function relevance_conserva_el_orden_que_devolvio_el_motor(): void
    {
        $this->seedRows(5);

        $orden = [4, 1, 5, 2];

        $ids = Relevance::constrain(TestModel::query(), $orden)->pluck('id')->all();

        $this->assertSame($orden, $ids);
    }

    #[Test]
    public function relevance_sin_ids_no_toca_la_consulta(): void
    {
        $sql = Relevance::order(TestModel::query(), [])->toSql();

        $this->assertStringNotContainsString('order by', strtolower($sql));
    }

    #[Test]
    public function relevance_usa_bindings_y_no_interpola_los_ids(): void
    {
        $query = Relevance::order(TestModel::query(), [3, 1, 2]);

        $this->assertSame([3, 1, 2], $query->getBindings());
    }

    #[Test]
    public function relevance_ignora_ids_invalidos_y_duplicados(): void
    {
        $query = Relevance::order(TestModel::query(), [3, 3, null, '', 1, false, 2]);

        $this->assertSame([3, 1, 2], $query->getBindings());
    }

    #[Test]
    public function constrain_con_lista_vacia_no_devuelve_nada(): void
    {
        // Que el motor no encuentre nada significa cero resultados, no todos.
        $this->seedRows(5);

        $count = Relevance::constrain(TestModel::query(), [])->count();

        $this->assertSame(0, $count);
    }

    #[Test]
    public function relevance_acepta_otra_columna(): void
    {
        $query = Relevance::order(TestModel::query(), [1, 2], 'owner_id');

        $this->assertStringContainsString('owner_id', $query->toSql());
    }

    /* -----------------------------------------------------------------
     | EngineFilter
     | ----------------------------------------------------------------- */

    #[Test]
    public function el_filtro_acota_por_los_ids_del_motor_y_respeta_su_orden(): void
    {
        $this->seedRows(10);
        FakeEngine::$ids = [7, 2, 9];

        $ids = $this->builder()->get(TestModel::class, ['q' => 'zapato', 'paginate' => 0], [
            'filters' => [FakeEngineFilter::class],
        ])->pluck('id')->all();

        $this->assertSame([7, 2, 9], $ids);
        $this->assertSame(['zapato'], FakeEngine::$queries);
    }

    #[Test]
    public function sin_termino_el_motor_ni_se_consulta(): void
    {
        $this->seedRows(5);

        $count = $this->builder()->get(TestModel::class, ['paginate' => 0], [
            'filters' => [FakeEngineFilter::class],
        ])->count();

        $this->assertSame(5, $count);
        $this->assertSame([], FakeEngine::$queries);
    }

    #[Test]
    public function si_el_motor_no_encuentra_nada_la_busqueda_devuelve_cero(): void
    {
        $this->seedRows(5);
        FakeEngine::$ids = [];

        $count = $this->builder()->get(TestModel::class, ['q' => 'nada', 'paginate' => 0], [
            'filters' => [FakeEngineFilter::class],
        ])->count();

        $this->assertSame(0, $count);
    }

    #[Test]
    public function los_filtros_sql_siguen_recortando_lo_que_devolvio_el_motor(): void
    {
        // Esta es la razon de ser del hibrido: la autorizacion no sale de SQL.
        $this->seedRows(10);
        FakeEngine::$ids = [1, 2, 3, 4];

        $ids = $this->builder()->get(
            TestModel::class,
            ['q' => 'algo', 'paginate' => 0],
            [
                'filters' => [FakeEngineFilter::class],
                'query' => TestModel::query()->where('owner_id', 0),
            ]
        )->pluck('id')->all();

        // De los 4 que dio el motor, solo los pares tienen owner_id = 0.
        $this->assertSame([2, 4], $ids);
    }

    #[Test]
    public function respeta_el_limite_de_ids_que_pide_al_motor(): void
    {
        $this->seedRows(10);
        FakeEngine::$ids = [1, 2, 3, 4, 5, 6];

        $ids = $this->builder()->get(TestModel::class, ['q' => 'zapato', 'paginate' => 0], [
            'filters' => [SmallLimitEngineFilter::class],
        ])->pluck('id')->all();

        $this->assertSame(3, FakeEngine::$lastLimit);
        $this->assertCount(3, $ids);
    }

    #[Test]
    public function respeta_la_longitud_minima_del_termino(): void
    {
        $this->seedRows(5);

        $this->builder()->get(TestModel::class, ['q' => 'ab', 'paginate' => 0], [
            'filters' => [SmallLimitEngineFilter::class],
        ]);

        $this->assertSame([], FakeEngine::$queries);
    }

    #[Test]
    public function puede_usarse_solo_como_filtro_sin_imponer_su_orden(): void
    {
        $this->seedRows(10);
        FakeEngine::$ids = [7, 2, 9];

        $ids = $this->builder()->get(
            TestModel::class,
            ['q' => 'algo', 'paginate' => 0, 'orderBy' => 'id', 'orderMode' => 'asc'],
            ['filters' => [UnorderedEngineFilter::class, IdFilter::class]]
        )->pluck('id')->all();

        $this->assertSame([2, 7, 9], $ids);
    }

    #[Test]
    public function el_filtro_de_motor_se_aplica_pronto_pero_despues_de_la_autorizacion(): void
    {
        $this->assertSame(-50, FilterMeta::priority(FakeEngineFilter::class));
        $this->assertSame(['q'], FilterMeta::keys(FakeEngineFilter::class));

        $this->assertLessThan(
            FilterMeta::priority(FakeEngineFilter::class),
            FilterMeta::priority(ManagedFilter::class)
        );
    }

    #[Test]
    public function un_modelo_sin_scout_da_un_mensaje_util(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('no es buscable');

        $this->builder()->get(TestModel::class, ['q' => 'algo', 'paginate' => 0], [
            'filters' => [DefaultEngineFilter::class],
        ]);
    }

    #[Test]
    public function si_el_motor_falla_la_busqueda_falla_en_vez_de_devolverlo_todo(): void
    {
        // Sin esto, un Elastic caido convertiria `?q=zapato` en "damelo todo":
        // parece que funciona y devuelve un millon de filas.
        $this->seedRows(5);

        $roto = new class extends EngineFilter
        {
            protected static function ids($query, $data, string $term): array
            {
                throw new \RuntimeException('motor caido');
            }
        };

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('motor caido');

        $this->builder()->get(TestModel::class, ['q' => 'algo', 'paginate' => 0], [
            'filters' => [$roto::class],
        ]);
    }

    #[Test]
    public function un_filtro_de_motor_se_marca_como_critico(): void
    {
        $this->assertTrue(FilterMeta::isCritical(FakeEngineFilter::class));
    }

    #[Test]
    public function strict_se_puede_activar_por_opciones_y_no_solo_por_config(): void
    {
        $this->expectException(\RuntimeException::class);

        $this->builder()->get(TestModel::class, ['paginate' => 0], [
            'filters' => [BrokenFilter::class],
            'strict' => true,
        ]);
    }

    #[Test]
    public function el_orden_por_relevancia_no_recibe_el_desempate_por_clave(): void
    {
        // El orden del motor ya es total (los ids son unicos), asi que anadir
        // la clave primaria detras solo ensuciaria el SQL.
        $this->seedRows(5);
        FakeEngine::$ids = [3, 1];

        $sql = $this->builder()->query(TestModel::class, ['q' => 'algo'], [
            'filters' => [FakeEngineFilter::class],
        ])->toSql();

        $this->assertSame(1, substr_count(strtolower($sql), 'order by'));
    }

    #[Test]
    public function combina_relevancia_con_paginacion(): void
    {
        $this->seedRows(20);
        FakeEngine::$ids = [15, 3, 8, 12, 1, 6];

        $page = $this->builder()->get(TestModel::class, ['q' => 'algo', 'paginate' => 2, 'page' => 2], [
            'filters' => [FakeEngineFilter::class],
        ]);

        $this->assertSame(6, $page->total());
        $this->assertSame([8, 12], $page->pluck('id')->all());
    }
}
