<?php

namespace Innoboxrr\SearchSurge\Tests\Unit;

use Illuminate\Contracts\Pagination\CursorPaginator;
use Illuminate\Contracts\Pagination\Paginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\LazyCollection;
use Innoboxrr\SearchSurge\Search\Builder;
use Innoboxrr\SearchSurge\Search\Filters\Common\IdFilter;
use Innoboxrr\SearchSurge\Search\Support\FilterRegistry;
use Innoboxrr\SearchSurge\Tests\Fixtures\Filters\BrokenFilter;
use Innoboxrr\SearchSurge\Tests\Fixtures\Filters\BrokenManagedFilter;
use Innoboxrr\SearchSurge\Tests\Fixtures\Filters\EarlyFilter;
use Innoboxrr\SearchSurge\Tests\Fixtures\Filters\KeyedNameFilter;
use Innoboxrr\SearchSurge\Tests\Fixtures\Filters\LateFilter;
use Innoboxrr\SearchSurge\Tests\Fixtures\Filters\MutatingFilter;
use Innoboxrr\SearchSurge\Tests\Models\Filters\TestModel\CreationFilter;
use Innoboxrr\SearchSurge\Tests\Models\TestModel;
use Innoboxrr\SearchSurge\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

class BuilderTest extends TestCase
{
    protected function builder(): Builder
    {
        return $this->app->make(Builder::class);
    }

    /* -----------------------------------------------------------------
     | Compatibilidad con la API de v2
     | ----------------------------------------------------------------- */

    #[Test]
    public function devuelve_una_coleccion_cuando_paginate_es_cero(): void
    {
        $result = $this->builder()->get(TestModel::class, ['paginate' => 0]);

        $this->assertInstanceOf(Collection::class, $result);
    }

    #[Test]
    public function devuelve_un_paginador_cuando_paginate_trae_un_numero(): void
    {
        $result = $this->builder()->get(TestModel::class, ['paginate' => 10]);

        $this->assertInstanceOf(LengthAwarePaginator::class, $result);
        $this->assertSame(10, $result->perPage());
    }

    #[Test]
    public function pagina_de_diez_en_diez_cuando_no_se_indica_nada(): void
    {
        $result = $this->builder()->get(TestModel::class);

        $this->assertInstanceOf(LengthAwarePaginator::class, $result);
        $this->assertSame(10, $result->perPage());
    }

    #[Test]
    public function sigue_aceptando_filtersPath_y_filtersNamespace(): void
    {
        $result = $this->builder()
            ->setBasePath(realpath(__DIR__.'/../..').DIRECTORY_SEPARATOR)
            ->get(TestModel::class, ['paginate' => 0], [
                'filtersPath' => 'tests'.DIRECTORY_SEPARATOR.'Models'.DIRECTORY_SEPARATOR.'Filters',
                'filtersNamespace' => 'Innoboxrr\\SearchSurge\\Tests\\Models\\Filters',
            ]);

        $this->assertInstanceOf(Collection::class, $result);
    }

    /* -----------------------------------------------------------------
     | Descubrimiento
     | ----------------------------------------------------------------- */

    #[Test]
    public function descubre_los_filtros_por_convencion_sin_configurar_nada(): void
    {
        $filters = $this->app->make(FilterRegistry::class)->resolve(TestModel::class);

        $this->assertContains(
            \Innoboxrr\SearchSurge\Tests\Models\Filters\TestModel\IdFilter::class,
            $filters
        );
        $this->assertContains(
            CreationFilter::class,
            $filters
        );
    }

    #[Test]
    public function una_lista_explicita_de_filtros_gana_a_la_convencion(): void
    {
        $filters = $this->app->make(FilterRegistry::class)
            ->resolve(TestModel::class, ['filters' => [KeyedNameFilter::class]]);

        $this->assertSame([KeyedNameFilter::class], $filters);
    }

    #[Test]
    public function el_registro_gana_a_la_convencion(): void
    {
        $registry = $this->app->make(FilterRegistry::class);
        $registry->register(TestModel::class, [KeyedNameFilter::class]);

        $filters = $registry->resolve(TestModel::class);

        $this->assertContains(KeyedNameFilter::class, $filters);
        $this->assertNotContains(
            \Innoboxrr\SearchSurge\Tests\Models\Filters\TestModel\IdFilter::class,
            $filters
        );
    }

    #[Test]
    public function lo_registrado_a_nivel_de_modelo_si_recibe_los_filtros_comunes(): void
    {
        // Registrar el conjunto del modelo no es lo mismo que pasar una lista
        // cerrada para una consulta concreta: aqui los comunes siguen aportando.
        $registry = $this->app->make(FilterRegistry::class);
        $registry->register(TestModel::class, [KeyedNameFilter::class]);

        $this->assertContains(
            IdFilter::class,
            $registry->resolve(TestModel::class)
        );
    }

    #[Test]
    public function una_lista_explicita_en_la_llamada_no_recibe_comunes(): void
    {
        // Colar filtros que nadie pidio, justo donde mas control se espera,
        // seria una sorpresa desagradable.
        $this->assertSame(
            [KeyedNameFilter::class],
            $this->app->make(FilterRegistry::class)
                ->resolve(TestModel::class, ['filters' => [KeyedNameFilter::class]])
        );
    }

    #[Test]
    public function un_namespace_registrado_localiza_los_filtros_sin_dar_la_ruta(): void
    {
        $registry = $this->app->make(FilterRegistry::class);
        $registry->registerNamespace(
            'Innoboxrr\\SearchSurge\\Tests\\Models',
            'Innoboxrr\\SearchSurge\\Tests\\Models\\Filters'
        );

        $this->assertContains(
            \Innoboxrr\SearchSurge\Tests\Models\Filters\TestModel\IdFilter::class,
            $registry->resolve(TestModel::class)
        );
    }

    #[Test]
    public function los_filtros_se_ordenan_por_prioridad(): void
    {
        $this->builder()->get(TestModel::class, ['paginate' => 0], [
            'filters' => [LateFilter::class, EarlyFilter::class],
        ]);

        $this->assertSame(['early', 'late'], LateFilter::$order);
    }

    /* -----------------------------------------------------------------
     | Aplicación de filtros
     | ----------------------------------------------------------------- */

    #[Test]
    public function omite_los_filtros_cuyas_claves_no_vienen_en_los_datos(): void
    {
        $this->builder()->get(TestModel::class, ['paginate' => 0], [
            'filters' => [KeyedNameFilter::class],
        ]);

        $this->assertSame(0, KeyedNameFilter::$calls);
    }

    #[Test]
    public function ejecuta_los_filtros_cuando_sus_claves_si_vienen(): void
    {
        $this->builder()->get(TestModel::class, ['paginate' => 0, 'name' => 'ana'], [
            'filters' => [KeyedNameFilter::class],
        ]);

        $this->assertSame(1, KeyedNameFilter::$calls);
    }

    #[Test]
    public function una_clave_presente_pero_vacia_no_dispara_el_filtro(): void
    {
        $this->builder()->get(TestModel::class, ['paginate' => 0, 'name' => ''], [
            'filters' => [KeyedNameFilter::class],
        ]);

        $this->assertSame(0, KeyedNameFilter::$calls);
    }

    #[Test]
    public function acepta_filtros_que_mutan_el_builder_sin_devolverlo(): void
    {
        $query = $this->builder()->query(TestModel::class, [], [
            'filters' => [MutatingFilter::class],
        ]);

        $this->assertSqlHas('name = ?', $query->toSql());
    }

    #[Test]
    public function un_filtro_roto_se_registra_y_se_omite(): void
    {
        Log::shouldReceive('error')->once();

        $result = $this->builder()->get(TestModel::class, ['paginate' => 0], [
            'filters' => [BrokenFilter::class],
        ]);

        $this->assertInstanceOf(Collection::class, $result);
    }

    #[Test]
    public function en_modo_estricto_un_filtro_roto_propaga(): void
    {
        config()->set('search-surge.strict', true);

        $this->expectException(\RuntimeException::class);

        $this->builder()->get(TestModel::class, ['paginate' => 0], [
            'filters' => [BrokenFilter::class],
        ]);
    }

    #[Test]
    public function un_filtro_de_autorizacion_roto_siempre_propaga(): void
    {
        // Nunca debe degradar en "devuelve todo": eso seria una fuga de datos.
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('filtro de permisos roto');

        $this->builder()->get(TestModel::class, ['paginate' => 0], [
            'filters' => [BrokenManagedFilter::class],
        ]);
    }

    /* -----------------------------------------------------------------
     | Estado y paginación
     | ----------------------------------------------------------------- */

    #[Test]
    public function el_estado_no_se_filtra_entre_dos_busquedas_de_la_misma_instancia(): void
    {
        $builder = $this->builder();

        $builder->get(TestModel::class, ['paginate' => 0, 'name' => 'ana'], [
            'filters' => [KeyedNameFilter::class],
        ]);

        // Sin 'filters' en la segunda llamada, debe volver a la convencion.
        $query = $builder->query(TestModel::class, ['paginate' => 0]);

        $this->assertSqlMissing('name like ?', $query->toSql());
    }

    #[Test]
    public function setOptions_preconfigura_el_builder_para_llamadas_posteriores(): void
    {
        // Patron de v2: preconfigurar y despues llamar a get() sin opciones.
        $builder = $this->builder()->setOptions(['filters' => [KeyedNameFilter::class]]);

        $builder->query(TestModel::class, ['name' => 'ana']);

        $this->assertSame(1, KeyedNameFilter::$calls);

        $builder->query(TestModel::class, ['name' => 'beto']);

        $this->assertSame(2, KeyedNameFilter::$calls);
    }

    #[Test]
    public function las_opciones_de_la_llamada_no_pisan_las_preconfiguradas_en_la_siguiente(): void
    {
        $builder = $this->builder()->setOptions(['paginator' => 'simple']);

        $builder->get(TestModel::class, ['paginate' => 5], ['paginator' => 'length_aware']);

        // La opcion puntual no debe quedarse pegada a la instancia.
        $this->assertInstanceOf(
            Paginator::class,
            $builder->get(TestModel::class, ['paginate' => 5])
        );
    }

    #[Test]
    public function registrar_filtros_invalida_lo_ya_memoizado(): void
    {
        $registry = $this->app->make(FilterRegistry::class);

        $options = ['filtersNamespace' => 'Innoboxrr\\SearchSurge\\Tests\\Models\\Filters'];

        $registry->resolve(TestModel::class, $options);
        $registry->register(TestModel::class, [KeyedNameFilter::class]);

        $filters = $registry->resolve(TestModel::class, $options);

        $this->assertContains(KeyedNameFilter::class, $filters);
        $this->assertNotContains(
            \Innoboxrr\SearchSurge\Tests\Models\Filters\TestModel\IdFilter::class,
            $filters
        );
    }

    #[Test]
    public function acota_paginate_al_maximo_configurado(): void
    {
        config()->set('search-surge.pagination.max_per_page', 50);

        $result = $this->builder()->get(TestModel::class, ['paginate' => 999999]);

        $this->assertSame(50, $result->perPage());
    }

    #[Test]
    public function un_paginate_no_numerico_cae_al_valor_por_defecto(): void
    {
        $result = $this->builder()->get(TestModel::class, ['paginate' => 'muchos']);

        $this->assertSame(10, $result->perPage());
    }

    #[Test]
    public function puede_usar_el_paginador_simple(): void
    {
        $result = $this->builder()->get(TestModel::class, ['paginate' => 5], [
            'paginator' => 'simple',
        ]);

        $this->assertInstanceOf(Paginator::class, $result);
        $this->assertNotInstanceOf(LengthAwarePaginator::class, $result);
    }

    #[Test]
    public function puede_usar_el_paginador_por_cursor(): void
    {
        $result = $this->builder()->get(TestModel::class, ['paginate' => 5], [
            'paginator' => 'cursor',
        ]);

        $this->assertInstanceOf(CursorPaginator::class, $result);
    }

    /* -----------------------------------------------------------------
     | Métodos nuevos
     | ----------------------------------------------------------------- */

    #[Test]
    public function count_no_trae_filas(): void
    {
        TestModel::query()->insert([
            ['name' => 'ana'],
            ['name' => 'beto'],
        ]);

        $this->assertSame(2, $this->builder()->count(TestModel::class));
    }

    #[Test]
    public function exists_usa_un_exists_y_no_un_count(): void
    {
        $this->assertFalse($this->builder()->exists(TestModel::class));

        TestModel::query()->insert(['name' => 'ana']);

        $this->assertTrue($this->builder()->exists(TestModel::class));
    }

    #[Test]
    public function lazy_recorre_sin_materializar_la_coleccion(): void
    {
        TestModel::query()->insert([
            ['name' => 'ana'],
            ['name' => 'beto'],
            ['name' => 'caro'],
        ]);

        $lazy = $this->builder()->lazy(TestModel::class, [], [], 2);

        $this->assertInstanceOf(LazyCollection::class, $lazy);
        $this->assertSame(3, $lazy->count());
    }

    #[Test]
    public function las_opciones_de_columnas_y_relaciones_llegan_a_la_consulta(): void
    {
        $sql = $this->builder()
            ->query(TestModel::class, [], ['columns' => ['id', 'name']])
            ->toSql();

        $this->assertSqlHas('select id, name', $sql);
    }

    #[Test]
    public function descarta_columnas_que_no_son_identificadores_validos(): void
    {
        $sql = $this->builder()
            ->query(TestModel::class, [], ['columns' => ['id', '(select 1)']])
            ->toSql();

        $this->assertSqlHas('select id', $sql);
        $this->assertStringNotContainsString('select 1', $sql);
    }

    #[Test]
    public function rechaza_una_clase_que_no_es_un_modelo(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->builder()->get(\stdClass::class);
    }

    #[Test]
    public function rechaza_una_clase_inexistente(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->builder()->get('App\\Models\\NoExiste');
    }

    #[Test]
    public function puede_partir_de_una_consulta_ya_empezada(): void
    {
        $sql = $this->builder()->query(TestModel::class, [], [
            'query' => TestModel::query()->where('owner_id', 7),
        ])->toSql();

        $this->assertSqlHas('owner_id = ?', $sql);
    }
}
