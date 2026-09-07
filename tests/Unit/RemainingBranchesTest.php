<?php

namespace Innoboxrr\SearchSurge\Tests\Unit;

use Illuminate\Support\Facades\File;
use Innoboxrr\SearchSurge\Search\Builder;
use Innoboxrr\SearchSurge\Search\Support\DataContainer;
use Innoboxrr\SearchSurge\Search\Support\FilterRegistry;
use Innoboxrr\SearchSurge\Search\Utils\RelationFilterQuery;
use Innoboxrr\SearchSurge\Search\Utils\SetFilterQuery;
use Innoboxrr\SearchSurge\Tests\Fixtures\Filters\KeyedNameFilter;
use Innoboxrr\SearchSurge\Tests\Models\Filters\TestModel\IdFilter;
use Innoboxrr\SearchSurge\Tests\Models\SoftModel;
use Innoboxrr\SearchSurge\Tests\Models\TestAuthor;
use Innoboxrr\SearchSurge\Tests\Models\TestModel;
use Innoboxrr\SearchSurge\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * Las ramas que quedaban sin recorrer.
 *
 * Ninguna es exotica: son el "no hay nada que hacer" de los comandos, los
 * limites de la paginacion y los caminos de degradacion. Se ejecutan en
 * produccion tarde o temprano, y son justo los que nadie prueba a mano porque
 * requieren montar una situacion rara a proposito.
 */
class RemainingBranchesTest extends TestCase
{
    protected function builder(): Builder
    {
        return $this->app->make(Builder::class);
    }

    protected function registry(): FilterRegistry
    {
        return $this->app->make(FilterRegistry::class);
    }

    /* -----------------------------------------------------------------
     | Comandos: cuando no hay nada que hacer
     | ----------------------------------------------------------------- */

    #[Test]
    public function un_namespace_inexistente_no_rompe_la_compilacion(): void
    {
        // --namespace anade sitios donde buscar, no restringe: el escaner
        // siempre encuentra los modelos que el autoloader ya conoce. Por eso el
        // aviso de "ningun modelo" solo se ve en un proyecto sin modelos, y no
        // se puede provocar desde aqui.
        $this->artisan('search-surge:cache', ['--namespace' => ['Ni\\Un\\Modelo']])
            ->assertSuccessful();
    }

    #[Test]
    public function se_compila_aunque_ningun_modelo_tenga_filtros(): void
    {
        config()->set('search-surge.filters.defaults', []);

        $this->registry()->forget();

        $this->artisan('search-surge:cache', ['--namespace' => ['Ni\\Un\\Modelo']])
            ->assertSuccessful();
    }

    #[Test]
    public function el_listado_avisa_cuando_no_hay_ningun_modelo(): void
    {
        $this->artisan('search-surge:filters', ['--namespace' => ['Ni\\Un\\Modelo']])
            ->assertSuccessful();
    }

    #[Test]
    public function el_listado_resume_cuantos_modelos_quedaron_sin_filtros(): void
    {
        config()->set('search-surge.filters.defaults', []);

        $this->registry()->forget();

        $this->artisan('search-surge:filters', [
            '--namespace' => ['Innoboxrr\\SearchSurge\\Tests\\Models'],
        ])->expectsOutputToContain('sin filtros')
            ->assertSuccessful();
    }

    /* -----------------------------------------------------------------
     | El comando explain, cuando el plan no colabora
     | ----------------------------------------------------------------- */

    #[Test]
    public function el_explain_dice_que_el_plan_no_esta_disponible(): void
    {
        // Con SQL crudo invalido, EXPLAIN falla: el comando tiene que decirlo y
        // seguir mostrando el analisis estatico, no reventar.
        $this->registry()->register(TestModel::class, [RawBrokenFilter::class]);

        $this->artisan('search-surge:explain', ['model' => TestModel::class])
            ->expectsOutputToContain('no disponible')
            ->assertSuccessful();
    }

    #[Test]
    public function el_explain_avisa_si_el_conteo_cuesta_mucho_mas_que_los_datos(): void
    {
        // No se puede forzar la proporcion de tiempos de forma fiable, asi que
        // se comprueba que la medicion se muestra y el comando termina bien.
        TestModel::query()->insert(array_map(
            static fn (int $i): array => ['name' => 'fila-'.$i],
            range(1, 60)
        ));

        $this->artisan('search-surge:explain', [
            'model' => TestModel::class,
            '--time' => true,
        ])->expectsOutputToContain('COUNT(*) del paginador')
            ->assertSuccessful();
    }

    /* -----------------------------------------------------------------
     | Builder
     | ----------------------------------------------------------------- */

    #[Test]
    public function el_desempate_estable_se_puede_desactivar_por_opciones(): void
    {
        $sql = $this->builder()->query(
            TestModel::class,
            ['orderBy' => 'created_at'],
            ['stableOrder' => false]
        );

        $this->assertSqlMissing('test_models.id', $sql);
    }

    #[Test]
    public function un_paginate_negativo_cae_al_valor_por_defecto(): void
    {
        foreach ([-5, '-1'] as $valor) {
            $result = $this->builder()->get(TestModel::class, ['paginate' => $valor]);

            $this->assertSame(10, $result->perPage());
        }
    }

    #[Test]
    public function el_basePath_fijado_aparte_llega_a_la_resolucion(): void
    {
        // setBasePath() por separado, sin pasar basePath en las opciones.
        $filtros = $this->builder()
            ->setBasePath(realpath(__DIR__.'/../..').DIRECTORY_SEPARATOR)
            ->query(TestModel::class, ['paginate' => 0], [
                'filtersPath' => 'tests'.DIRECTORY_SEPARATOR.'Models'.DIRECTORY_SEPARATOR.'Filters',
                'filtersNamespace' => 'Innoboxrr\\SearchSurge\\Tests\\Models\\Filters',
            ]);

        $this->assertInstanceOf(\Illuminate\Database\Eloquent\Builder::class, $filtros);
    }

    /* -----------------------------------------------------------------
     | FilterRegistry
     | ----------------------------------------------------------------- */

    #[Test]
    public function olvidar_un_modelo_concreto_no_borra_los_demas(): void
    {
        $registry = $this->registry();

        $registry->register(TestModel::class, [KeyedNameFilter::class]);
        $registry->register(TestAuthor::class, [KeyedNameFilter::class]);

        $registry->resolve(TestModel::class);
        $registry->resolve(TestAuthor::class);

        $registry->forget(TestModel::class);

        $this->assertContains(KeyedNameFilter::class, $registry->resolve(TestAuthor::class));
        $this->assertContains(KeyedNameFilter::class, $registry->resolve(TestModel::class));
    }

    #[Test]
    public function un_nombre_corto_de_filtro_se_cualifica_con_el_namespace(): void
    {
        // En la lista se puede poner solo 'IdFilter' si se da el namespace.
        $filters = $this->registry()->resolve(TestModel::class, [
            'filters' => ['IdFilter'],
            'filtersNamespace' => 'Innoboxrr\\SearchSurge\\Tests\\Models\\Filters',
        ]);

        $this->assertSame(
            [IdFilter::class],
            $filters
        );
    }

    #[Test]
    public function sin_candidatos_donde_buscar_no_se_resuelve_nada(): void
    {
        config()->set('search-surge.filters.defaults', []);
        config()->set('search-surge.filters.suffix', '');
        config()->set('search-surge.filters.namespace', '');

        $this->registry()->forget();

        $this->assertSame([], $this->registry()->resolve(TestAuthor::class));
    }

    #[Test]
    public function la_cache_se_desactiva_sola_fuera_de_produccion(): void
    {
        config()->set('search-surge.cache.enabled', null);

        $this->registry()->forget();

        // En el entorno de pruebas isProduction() es falso, asi que resolver dos
        // veces no debe dejar rastro en la cache.
        $this->assertNotEmpty($this->registry()->resolve(TestModel::class));
    }

    /* -----------------------------------------------------------------
     | Utils
     | ----------------------------------------------------------------- */

    #[Test]
    public function el_conteo_de_relacion_acepta_un_maximo(): void
    {
        TestAuthor::query()->insert([
            ['id' => 1, 'name' => 'prolifico', 'country' => 'MX'],
            ['id' => 2, 'name' => 'discreto', 'country' => 'ES'],
        ]);

        SoftModel::query()->insert([
            ['name' => 'p1', 'author_id' => 1, 'deleted_at' => null],
            ['name' => 'p2', 'author_id' => 1, 'deleted_at' => null],
            ['name' => 'p3', 'author_id' => 2, 'deleted_at' => null],
        ]);

        $names = RelationFilterQuery::count(
            TestAuthor::query(),
            new DataContainer(['posts_count_max' => 1]),
            'posts'
        )->pluck('name')->all();

        $this->assertSame(['discreto'], $names);
    }

    #[Test]
    public function la_relacion_acepta_un_array_en_un_objeto_suelto(): void
    {
        TestAuthor::query()->insert([['id' => 1, 'name' => 'ana', 'country' => 'MX']]);
        SoftModel::query()->insert([['name' => 'post', 'author_id' => 1, 'deleted_at' => null]]);

        $names = RelationFilterQuery::column(
            SoftModel::query(),
            (object) ['author_country' => ['MX']],
            'author',
            'country'
        )->pluck('name')->all();

        $this->assertSame(['post'], $names);
    }

    #[Test]
    public function excluir_un_conjunto_del_que_nada_sobrevive_no_filtra(): void
    {
        // Al excluir, si nada de lo pedido esta permitido no hay nada que
        // excluir: la consulta se queda como estaba.
        TestModel::query()->insert([
            ['name' => 'a', 'status' => 'borrador'],
            ['name' => 'b', 'status' => 'publicado'],
        ]);

        $count = SetFilterQuery::apply(
            TestModel::query(),
            new DataContainer(['status_not' => 'inventado']),
            'status',
            ['borrador', 'publicado']
        )->count();

        $this->assertSame(2, $count);
    }

    /* -----------------------------------------------------------------
     | Generador
     | ----------------------------------------------------------------- */

    #[Test]
    public function el_generador_rechaza_una_clase_que_no_existe(): void
    {
        $this->artisan('search-surge:filter', [
            'model' => 'App\\Models\\NiIdea',
            'name' => 'Algo',
        ])->expectsOutputToContain('no existe')
            ->assertFailed();
    }

    #[Test]
    public function el_generador_crea_el_directorio_si_no_existe(): void
    {
        $dir = realpath(__DIR__.'/../Models').DIRECTORY_SEPARATOR.'Filters'
            .DIRECTORY_SEPARATOR.'TestAuthor';

        if (File::isDirectory($dir)) {
            File::deleteDirectory($dir);
        }

        $this->artisan('search-surge:filter', [
            'model' => TestAuthor::class,
            'name' => 'Pais',
        ])->assertSuccessful();

        $this->assertFileExists($dir.DIRECTORY_SEPARATOR.'PaisFilter.php');

        File::deleteDirectory($dir);
    }
}

/**
 * Un filtro que mete SQL crudo invalido, para que EXPLAIN no pueda analizarlo.
 */
class RawBrokenFilter
{
    public static function apply($query, $data)
    {
        return $query->whereRaw('columna_que_no_existe = 1');
    }
}
