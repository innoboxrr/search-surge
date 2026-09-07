<?php

namespace Innoboxrr\SearchSurge\Tests\Unit;

use Innoboxrr\SearchSurge\Facades\SearchSurge;
use Innoboxrr\SearchSurge\Tests\Fixtures\IndexRequest;
use Innoboxrr\SearchSurge\Tests\Models\Filters\TestModel\CreationFilter;
use Innoboxrr\SearchSurge\Tests\Models\Filters\TestModel\IdFilter;
use Innoboxrr\SearchSurge\Tests\Models\TestModel;
use Innoboxrr\SearchSurge\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * Recorrido completo por HTTP, tal y como lo usan los paquetes: una ruta con un
 * FormRequest que llama a SearchSurge con $request->all().
 *
 * Es el test que garantiza que la resolución por convención, la paginación y
 * los filtros encajan de verdad, no solo pieza a pieza.
 */
class IntegrationTest extends TestCase
{
    protected function defineRoutes($router): void
    {
        $router->get('/items', fn (IndexRequest $request) => $request->handle());
    }

    protected function seedItems(): void
    {
        $rows = [];

        foreach (['ana', 'antonio', 'beto', 'carla', 'diego'] as $i => $name) {
            $rows[] = [
                'name' => $name,
                'owner_id' => ($i % 2) + 1,
                'created_at' => '2026-01-0'.($i + 1).' 12:00:00',
                'updated_at' => '2026-02-0'.($i + 1).' 12:00:00',
            ];
        }

        TestModel::query()->insert($rows);
    }

    /* -----------------------------------------------------------------
     | Peticiones reales
     | ----------------------------------------------------------------- */

    #[Test]
    public function una_peticion_sin_parametros_devuelve_la_primera_pagina(): void
    {
        $this->seedItems();

        $response = $this->getJson('/items');

        $response->assertOk();
        $this->assertSame(5, $response->json('total'));
        $this->assertSame(10, $response->json('per_page'));
        $this->assertCount(5, $response->json('data'));
    }

    #[Test]
    public function filtra_por_id_desde_la_query_string(): void
    {
        $this->seedItems();

        $id = TestModel::query()->where('name', 'beto')->value('id');

        $response = $this->getJson('/items?id='.$id);

        $response->assertOk();
        $this->assertSame(1, $response->json('total'));
        $this->assertSame('beto', $response->json('data.0.name'));
    }

    #[Test]
    public function ordena_por_created_at_desde_la_query_string(): void
    {
        $this->seedItems();

        $asc = $this->getJson('/items?orderBy=created_at&orderMode=asc')->json('data.0.name');
        $desc = $this->getJson('/items?orderBy=created_at&orderMode=desc')->json('data.0.name');

        $this->assertSame('ana', $asc);
        $this->assertSame('diego', $desc);
    }

    #[Test]
    public function filtra_por_rango_de_fechas_desde_la_query_string(): void
    {
        $this->seedItems();

        $response = $this->getJson('/items?created_at_start_date=2026-01-02&created_at_end_date=2026-01-04');

        $response->assertOk();
        $this->assertSame(3, $response->json('total'));
    }

    #[Test]
    public function el_rango_de_updated_at_no_se_confunde_con_created_at(): void
    {
        $this->seedItems();

        // Los updated_at estan en febrero; filtrar enero por updated_at no debe
        // devolver nada. En v2 devolvia filas porque miraba created_at.
        $response = $this->getJson('/items?updated_at_start_date=2026-01-01&updated_at_end_date=2026-01-31');

        $response->assertOk();
        $this->assertSame(0, $response->json('total'));

        $febrero = $this->getJson('/items?updated_at_start_date=2026-02-01&updated_at_end_date=2026-02-28');

        $this->assertSame(5, $febrero->json('total'));
    }

    #[Test]
    public function paginate_cero_devuelve_una_coleccion_plana(): void
    {
        $this->seedItems();

        $response = $this->getJson('/items?paginate=0');

        $response->assertOk();
        $this->assertCount(5, $response->json());
        $this->assertNull($response->json('total'));
    }

    #[Test]
    public function pagina_correctamente_a_traves_de_varias_paginas(): void
    {
        $this->seedItems();

        $p1 = $this->getJson('/items?paginate=2&page=1');
        $p2 = $this->getJson('/items?paginate=2&page=2');
        $p3 = $this->getJson('/items?paginate=2&page=3');

        $this->assertCount(2, $p1->json('data'));
        $this->assertCount(2, $p2->json('data'));
        $this->assertCount(1, $p3->json('data'));

        $ids = array_merge(
            array_column($p1->json('data'), 'id'),
            array_column($p2->json('data'), 'id'),
            array_column($p3->json('data'), 'id'),
        );

        $this->assertSame(5, count(array_unique($ids)));
    }

    #[Test]
    public function la_basura_en_parametros_de_control_se_ignora_sin_alterar_el_resultado(): void
    {
        $this->seedItems();

        // orderBy, orderMode, operator y paginate son parametros de control:
        // un valor invalido se descarta y la busqueda sigue devolviendo todo.
        $response = $this->getJson('/items?'.http_build_query([
            'orderBy' => 'DROP TABLE test_models',
            'orderMode' => 'desc; --',
            'operator' => 'UNION',
            'created_at' => 'no-es-fecha',
            'paginate' => 'muchos',
            'filtro_inexistente' => 'valor',
        ]));

        $response->assertOk();
        $this->assertSame(5, $response->json('total'));
        $this->assertSame(10, $response->json('per_page'));
    }

    #[Test]
    public function la_basura_en_un_valor_de_filtro_no_coincide_con_nada(): void
    {
        $this->seedItems();

        // Un id no numerico es un filtro que no casa con ninguna fila. No se
        // ignora en silencio: se responde "cero resultados", que es lo honesto.
        $response = $this->getJson('/items?id='.urlencode("1' OR '1'='1"));

        $response->assertOk();
        $this->assertSame(0, $response->json('total'));
        $this->assertSame(5, TestModel::query()->count());
    }

    /* -----------------------------------------------------------------
     | El facade y el registro en un ciclo real
     | ----------------------------------------------------------------- */

    #[Test]
    public function el_facade_resuelve_los_filtros_por_convencion(): void
    {
        $filters = SearchSurge::filtersFor(TestModel::class);

        $this->assertNotEmpty($filters);
        $this->assertContains(
            IdFilter::class,
            $filters
        );
    }

    #[Test]
    public function registrar_un_namespace_desde_un_provider_afecta_a_la_resolucion(): void
    {
        SearchSurge::forgetFilters();
        SearchSurge::registerNamespace(
            'Innoboxrr\\SearchSurge\\Tests\\Models',
            'Innoboxrr\\SearchSurge\\Tests\\Models\\Filters'
        );

        $this->assertContains(
            CreationFilter::class,
            SearchSurge::filtersFor(TestModel::class)
        );
    }

    #[Test]
    public function el_manifiesto_compilado_da_el_mismo_resultado_que_la_convencion(): void
    {
        $porConvencion = SearchSurge::filtersFor(TestModel::class);

        $this->artisan('search-surge:cache', [
            '--namespace' => ['Innoboxrr\\SearchSurge\\Tests\\Models'],
        ])->assertSuccessful();

        SearchSurge::forgetFilters();

        $delManifiesto = SearchSurge::filtersFor(TestModel::class);

        $this->assertSame($porConvencion, $delManifiesto);

        $this->artisan('search-surge:clear')->assertSuccessful();
    }

    #[Test]
    public function la_busqueda_da_el_mismo_resultado_con_y_sin_manifiesto(): void
    {
        $this->seedItems();

        $sinManifiesto = $this->getJson('/items?orderBy=name&orderMode=asc')->json('data');

        $this->artisan('search-surge:cache', [
            '--namespace' => ['Innoboxrr\\SearchSurge\\Tests\\Models'],
        ])->assertSuccessful();

        SearchSurge::forgetFilters();

        $conManifiesto = $this->getJson('/items?orderBy=name&orderMode=asc')->json('data');

        $this->assertSame(
            array_column($sinManifiesto, 'id'),
            array_column($conManifiesto, 'id')
        );

        $this->artisan('search-surge:clear')->assertSuccessful();
    }

    /* -----------------------------------------------------------------
     | Equivalencia entre las distintas vías de configuración
     | ----------------------------------------------------------------- */

    #[Test]
    public function las_cuatro_vias_de_configuracion_resuelven_lo_mismo(): void
    {
        $esperado = SearchSurge::filtersFor(TestModel::class);

        // 1. Namespace explicito en las opciones.
        $porNamespace = SearchSurge::filtersFor(TestModel::class, [
            'filtersNamespace' => 'Innoboxrr\\SearchSurge\\Tests\\Models\\Filters',
        ]);

        // 2. Ruta fisica (modo legado de v2).
        $porRuta = SearchSurge::filtersFor(TestModel::class, [
            'basePath' => realpath(__DIR__.'/../..').DIRECTORY_SEPARATOR,
            'filtersPath' => 'tests'.DIRECTORY_SEPARATOR.'Models'.DIRECTORY_SEPARATOR.'Filters',
            'filtersNamespace' => 'Innoboxrr\\SearchSurge\\Tests\\Models\\Filters',
        ]);

        // 3. Lista explicita.
        $porLista = SearchSurge::filtersFor(TestModel::class, ['filters' => $esperado]);

        $this->assertSame($esperado, $porNamespace);
        $this->assertSame($esperado, $porRuta);
        $this->assertSame($esperado, $porLista);
    }
}
