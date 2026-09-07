<?php

namespace Innoboxrr\SearchSurge\Tests\Unit;

use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Innoboxrr\SearchSurge\Exceptions\PageLimitExceededException;
use Innoboxrr\SearchSurge\Facades\SearchSurge;
use Innoboxrr\SearchSurge\Search\Builder;
use Innoboxrr\SearchSurge\Search\Support\ComposerLocator;
use Innoboxrr\SearchSurge\Search\Support\DataContainer;
use Innoboxrr\SearchSurge\Search\Support\Driver;
use Innoboxrr\SearchSurge\Search\Support\FilterRegistry;
use Innoboxrr\SearchSurge\Search\Utils\Order;
use Innoboxrr\SearchSurge\Search\Utils\SetFilterQuery;
use Innoboxrr\SearchSurge\Tests\Fixtures\Filters\KeyedNameFilter;
use Innoboxrr\SearchSurge\Tests\Models\PlainModel;
use Innoboxrr\SearchSurge\Tests\Models\SoftModel;
use Innoboxrr\SearchSurge\Tests\Models\TestAuthor;
use Innoboxrr\SearchSurge\Tests\Models\TestModel;
use Innoboxrr\SearchSurge\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * Cubre la superficie publica que las pruebas por escenario no llegan a tocar.
 *
 * No son casos exoticos: son metodos que alguien va a llamar desde su app, y un
 * metodo que nadie ha ejecutado nunca es un metodo que no se sabe si funciona.
 */
class ApiSurfaceTest extends TestCase
{
    protected function builder(): Builder
    {
        return $this->app->make(Builder::class);
    }

    /* -----------------------------------------------------------------
     | Builder
     | ----------------------------------------------------------------- */

    #[Test]
    public function first_devuelve_un_modelo_o_null(): void
    {
        $this->assertNull($this->builder()->first(TestModel::class));

        TestModel::query()->insert([['name' => 'ana'], ['name' => 'beto']]);

        $modelo = $this->builder()->first(TestModel::class, ['orderBy' => 'id', 'orderMode' => 'asc']);

        $this->assertInstanceOf(TestModel::class, $modelo);
        $this->assertSame('ana', $modelo->name);
    }

    #[Test]
    public function first_aplica_los_filtros(): void
    {
        TestModel::query()->insert([['id' => 1, 'name' => 'ana'], ['id' => 2, 'name' => 'beto']]);

        $this->assertSame('beto', $this->builder()->first(TestModel::class, ['id' => 2])->name);
    }

    /* -----------------------------------------------------------------
     | Facade
     | ----------------------------------------------------------------- */

    #[Test]
    public function el_facade_registra_filtros_de_un_modelo(): void
    {
        SearchSurge::registerFilters(TestModel::class, [KeyedNameFilter::class]);

        $this->assertContains(KeyedNameFilter::class, SearchSurge::filtersFor(TestModel::class));
    }

    #[Test]
    public function el_facade_registra_varios_modelos_de_una_vez(): void
    {
        SearchSurge::registerFilterMap([
            TestModel::class => [KeyedNameFilter::class],
            TestAuthor::class => [KeyedNameFilter::class],
        ]);

        $this->assertContains(KeyedNameFilter::class, SearchSurge::filtersFor(TestModel::class));
        $this->assertContains(KeyedNameFilter::class, SearchSurge::filtersFor(TestAuthor::class));
    }

    #[Test]
    public function el_registro_acepta_un_mapa_completo(): void
    {
        $registry = $this->app->make(FilterRegistry::class);

        $registry->registerMany([TestAuthor::class => [KeyedNameFilter::class]]);

        $this->assertContains(KeyedNameFilter::class, $registry->resolve(TestAuthor::class));
    }

    /* -----------------------------------------------------------------
     | Trait, con sus valores por defecto
     | ----------------------------------------------------------------- */

    #[Test]
    public function el_trait_no_declara_filtros_ni_opciones_por_defecto(): void
    {
        $this->assertSame([], PlainModel::surgeFilters());
        $this->assertSame([], PlainModel::surgeOptions());
    }

    #[Test]
    public function un_modelo_con_el_trait_y_sin_declarar_nada_sigue_funcionando(): void
    {
        PlainModel::query()->insert([['id' => 1, 'name' => 'ana'], ['id' => 2, 'name' => 'beto']]);

        // Sin filtros propios, resuelve por convencion y recibe los comunes.
        $this->assertSame(1, PlainModel::surgeCount(['id' => 1]));
        $this->assertSame(2, PlainModel::surgeCount());
    }

    /* -----------------------------------------------------------------
     | Excepción de página profunda
     | ----------------------------------------------------------------- */

    #[Test]
    public function la_excepcion_de_pagina_se_renderiza_como_json_400(): void
    {
        $respuesta = (new PageLimitExceededException(9000, 500))->render();

        $this->assertSame(400, $respuesta->getStatusCode());

        $cuerpo = $respuesta->getData(true);

        $this->assertSame(9000, $cuerpo['page']);
        $this->assertSame(500, $cuerpo['max_page']);
        $this->assertStringContainsString('9000', $cuerpo['message']);
    }

    /* -----------------------------------------------------------------
     | DataContainer: el resto de la superficie
     | ----------------------------------------------------------------- */

    #[Test]
    public function input_es_un_alias_de_get(): void
    {
        $data = new DataContainer(['a' => 1]);

        $this->assertSame(1, $data->input('a'));
        $this->assertSame('x', $data->input('inexistente', 'x'));
    }

    #[Test]
    public function only_y_except_recortan_el_contenedor(): void
    {
        $data = new DataContainer(['a' => 1, 'b' => 2, 'c' => 3]);

        $this->assertSame(['a' => 1, 'c' => 3], $data->only(['a', 'c']));
        $this->assertSame(['b' => 2], $data->except(['a', 'c']));
    }

    #[Test]
    public function se_puede_escribir_por_propiedad_y_por_indice(): void
    {
        $data = new DataContainer([]);

        $data->nombre = 'ana';
        $data['edad'] = 30;
        $data[] = 'suelto';

        $this->assertSame('ana', $data->nombre);
        $this->assertSame(30, $data['edad']);
        $this->assertContains('suelto', $data->all());

        unset($data->nombre);
        unset($data['edad']);

        $this->assertFalse(isset($data->nombre));
        $this->assertFalse($data->has('edad'));
    }

    #[Test]
    public function se_serializa_a_json(): void
    {
        $data = new DataContainer(['a' => 1, 'b' => 'dos']);

        $this->assertSame('{"a":1,"b":"dos"}', json_encode($data));
        $this->assertSame(['a' => 1, 'b' => 'dos'], $data->jsonSerialize());
    }

    /* -----------------------------------------------------------------
     | Support
     | ----------------------------------------------------------------- */

    #[Test]
    public function el_localizador_sabe_si_hay_autoloader(): void
    {
        // En la suite siempre lo hay: la usa el propio PHPUnit.
        $this->assertTrue(ComposerLocator::available());
    }

    #[Test]
    public function driver_is_compara_contra_una_lista(): void
    {
        $query = TestModel::query();

        $this->assertTrue(Driver::is($query, [static::driver()]));
        $this->assertFalse(Driver::is($query, ['motor-inventado']));
    }

    #[Test]
    public function driver_degrada_a_cadena_vacia_con_una_conexion_ajena(): void
    {
        // getDriverName() no esta en ConnectionInterface: una conexion
        // personalizada haria fatal si no se comprobara el tipo.
        $this->assertSame('', Driver::ofConnection(new \stdClass));
        $this->assertSame('', Driver::ofConnection(null));
    }

    #[Test]
    public function el_descubrimiento_se_persiste_cuando_la_cache_esta_activa(): void
    {
        config()->set('search-surge.cache.enabled', true);

        $registry = $this->app->make(FilterRegistry::class);
        $primera = $registry->resolve(TestModel::class);

        // Otro registro, sin memoizacion propia: debe salir de la cache.
        $otro = new FilterRegistry($this->app, $this->app->make('config'), $this->app->make('cache'));

        $this->assertSame($primera, $otro->resolve(TestModel::class));
    }

    /* -----------------------------------------------------------------
     | Utils
     | ----------------------------------------------------------------- */

    #[Test]
    public function el_orden_por_defecto_solo_entra_si_nadie_pidio_otro(): void
    {
        $sinPeticion = Order::fallback(TestModel::query(), new DataContainer([]), 'id', 'desc');

        $this->assertSqlHas('order by id desc', $sinPeticion);
    }

    #[Test]
    public function el_orden_por_defecto_respeta_lo_que_pidio_el_usuario(): void
    {
        $conPeticion = Order::fallback(
            TestModel::query(),
            new DataContainer(['orderBy' => 'name']),
            'id',
            'desc'
        );

        $this->assertSqlMissing('order by', $conPeticion);
    }

    #[Test]
    public function el_orden_por_defecto_no_pisa_un_orden_ya_aplicado(): void
    {
        $ya = Order::fallback(
            TestModel::query()->orderBy('name'),
            new DataContainer([]),
            'id',
            'desc'
        );

        $this->assertSame(1, $this->sqlCount('order by', $ya));
        $this->assertSqlMissing('id desc', $ya);
    }

    #[Test]
    public function el_orden_por_defecto_acepta_ascendente(): void
    {
        $asc = Order::fallback(TestModel::query(), new DataContainer([]), 'id', 'asc');

        $this->assertSqlHas('order by id asc', $asc);
    }

    #[Test]
    public function el_conjunto_expone_las_claves_que_consume(): void
    {
        $this->assertSame(['status', 'status_not'], SetFilterQuery::keys('status'));
    }

    /* -----------------------------------------------------------------
     | Comando explain
     | ----------------------------------------------------------------- */

    #[Test]
    public function el_comando_explain_puede_analizar_tambien_el_conteo(): void
    {
        $this->artisan('search-surge:explain', [
            'model' => TestModel::class,
            '--count' => true,
        ])->expectsOutputToContain('COUNT(*) del paginador')
            ->assertSuccessful();
    }

    /* -----------------------------------------------------------------
     | Opciones del Builder poco transitadas
     | ----------------------------------------------------------------- */

    #[Test]
    public function la_opcion_withCount_llega_a_la_consulta(): void
    {
        $sql = $this->builder()
            ->query(TestAuthor::class, [], ['withCount' => ['posts']])
            ->toSql();

        $this->assertStringContainsString('posts_count', $sql);
    }

    #[Test]
    public function la_opcion_with_precarga_relaciones(): void
    {
        $query = $this->builder()->query(TestAuthor::class, [], ['with' => ['posts']]);

        $this->assertArrayHasKey('posts', $query->getEagerLoads());
    }

    #[Test]
    public function se_pueden_quitar_los_scopes_globales(): void
    {
        $query = $this->builder()->query(
            SoftModel::class,
            [],
            ['withoutGlobalScopes' => true]
        );

        $this->assertInstanceOf(EloquentBuilder::class, $query);
        $this->assertSqlMissing('deleted_at is null', $query);
    }
}
