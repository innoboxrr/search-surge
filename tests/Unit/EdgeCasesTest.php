<?php

namespace Innoboxrr\SearchSurge\Tests\Unit;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Event;
use Innoboxrr\SearchSurge\Events\SearchExecuted;
use Innoboxrr\SearchSurge\Search\Builder;
use Innoboxrr\SearchSurge\Search\Support\DataContainer;
use Innoboxrr\SearchSurge\Search\Support\FilterMeta;
use Innoboxrr\SearchSurge\Search\Support\FilterRegistry;
use Innoboxrr\SearchSurge\Search\Utils\Order;
use Innoboxrr\SearchSurge\Search\Utils\Relevance;
use Innoboxrr\SearchSurge\Tests\Models\TestAuthor;
use Innoboxrr\SearchSurge\Tests\Models\TestModel;
use Innoboxrr\SearchSurge\Tests\Models\TestUser;
use Innoboxrr\SearchSurge\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

/**
 * Los recodos: las ramas que solo se recorren cuando la entrada tiene una forma
 * poco habitual. Son las que nadie prueba a mano y las que se rompen sin que
 * nadie se entere.
 */
class EdgeCasesTest extends TestCase
{
    protected function builder(): Builder
    {
        return $this->app->make(Builder::class);
    }

    /* -----------------------------------------------------------------
     | Order::orderByAny
     | ----------------------------------------------------------------- */

    #[Test]
    public function el_prefijo_menos_invierte_esa_columna(): void
    {
        $sql = Order::orderByAny(
            TestModel::query(),
            new DataContainer(['orderBy' => 'name,-created_at']),
            ['name', 'created_at']
        );

        $this->assertSqlHas('order by name asc, created_at desc', $sql);
    }

    #[Test]
    public function el_prefijo_mas_fuerza_ascendente(): void
    {
        $sql = Order::orderByAny(
            TestModel::query(),
            new DataContainer(['orderBy' => '+name', 'orderMode' => 'desc']),
            ['name']
        );

        $this->assertSqlHas('order by name asc', $sql);
    }

    #[Test]
    public function las_columnas_fuera_de_la_lista_blanca_se_descartan(): void
    {
        $sql = Order::orderByAny(
            TestModel::query(),
            new DataContainer(['orderBy' => 'name,password,secreto']),
            ['name']
        );

        $this->assertSqlHas('order by name asc', $sql);
        $this->assertSqlMissing('password', $sql);
        $this->assertSqlMissing('secreto', $sql);
    }

    #[Test]
    public function los_huecos_de_la_lista_se_ignoran(): void
    {
        $sql = Order::orderByAny(
            TestModel::query(),
            new DataContainer(['orderBy' => 'name,,  ,created_at']),
            ['name', 'created_at']
        );

        $this->assertSame(2, substr_count(strtolower($this->sqlOf($sql)), 'asc'));
    }

    public static function entradasQueNoOrdenan(): array
    {
        return [
            'vacia' => ['', ['name']],
            'nula' => [null, ['name']],
            'no es texto' => [['name'], ['name']],
            'sin lista blanca' => ['name', []],
        ];
    }

    #[Test]
    #[DataProvider('entradasQueNoOrdenan')]
    public function orderByAny_no_ordena_sin_una_entrada_utilizable(mixed $orderBy, array $allowed): void
    {
        $sql = Order::orderByAny(
            TestModel::query(),
            new DataContainer(['orderBy' => $orderBy]),
            $allowed
        );

        $this->assertSqlMissing('order by', $sql);
    }

    /* -----------------------------------------------------------------
     | Relevance
     | ----------------------------------------------------------------- */

    #[Test]
    public function la_relevancia_puede_ordenar_por_otra_columna(): void
    {
        TestAuthor::query()->insert([
            ['id' => 1, 'name' => 'ana', 'country' => 'MX'],
            ['id' => 2, 'name' => 'beto', 'country' => 'ES'],
            ['id' => 3, 'name' => 'caro', 'country' => 'AR'],
        ]);

        $names = Relevance::constrain(TestAuthor::query(), ['ES', 'AR'], 'country')
            ->pluck('name')->all();

        $this->assertSame(['beto', 'caro'], $names);
    }

    #[Test]
    public function la_relevancia_acepta_una_columna_cualificada(): void
    {
        $sql = Relevance::order(TestModel::query(), [3, 1], 'test_models.id');

        $this->assertSqlHas('test_models.id', $sql);
    }

    #[Test]
    public function la_expresion_de_relevancia_se_adapta_al_motor(): void
    {
        $sql = strtolower($this->sqlOf(Relevance::order(TestModel::query(), [3, 1, 2])));

        match (static::driver()) {
            'mysql', 'mariadb' => $this->assertStringContainsString('field(', $sql),
            // Los dos lados casteados: PostgreSQL deduce el tipo del ARRAY antes
            // de conocer los parametros y asume text[].
            'pgsql' => $this->assertStringContainsString('array_position(array[?, ?, ?]::text[]', $sql),
            default => $this->assertStringContainsString('case', $sql),
        };
    }

    #[Test]
    public function la_relevancia_ordena_de_verdad_en_este_motor(): void
    {
        // No basta con mirar el SQL: en PostgreSQL la expresion se generaba bien
        // y reventaba al ejecutarse por un desajuste de tipos.
        TestModel::query()->insert([
            ['id' => 1, 'name' => 'uno'],
            ['id' => 2, 'name' => 'dos'],
            ['id' => 3, 'name' => 'tres'],
        ]);

        $orden = [3, 1, 2];

        $this->assertSame(
            $orden,
            Relevance::constrain(TestModel::query(), $orden)->pluck('id')->all()
        );
    }

    /* -----------------------------------------------------------------
     | FilterMeta
     | ----------------------------------------------------------------- */

    #[Test]
    public function un_filtro_critico_sin_prioridad_explicita_se_adelanta(): void
    {
        $filtro = new class
        {
            public static bool $critical = true;

            public static function apply($query, $data)
            {
                return $query;
            }
        };

        $this->assertTrue(FilterMeta::isCritical($filtro::class));
        $this->assertSame(-100, FilterMeta::priority($filtro::class));
    }

    #[Test]
    public function un_filtro_critico_puede_fijar_su_propia_prioridad(): void
    {
        $filtro = new class
        {
            public static bool $critical = true;

            public static int $priority = -7;

            public static function apply($query, $data)
            {
                return $query;
            }
        };

        $this->assertSame(-7, FilterMeta::priority($filtro::class));
    }

    #[Test]
    public function critical_solo_cuenta_si_es_exactamente_true(): void
    {
        $filtro = new class
        {
            public static $critical = 'si';

            public static function apply($query, $data)
            {
                return $query;
            }
        };

        $this->assertFalse(FilterMeta::isCritical($filtro::class));
    }

    #[Test]
    public function los_metadatos_se_memoizan_por_clase(): void
    {
        $filtro = new class
        {
            public static array $keys = ['a'];

            public static function apply($query, $data)
            {
                return $query;
            }
        };

        $primera = FilterMeta::keys($filtro::class);

        $this->assertSame($primera, FilterMeta::keys($filtro::class));
        $this->assertSame(['a'], $primera);
    }

    /* -----------------------------------------------------------------
     | Builder
     | ----------------------------------------------------------------- */

    #[Test]
    public function un_modelo_enlazado_en_el_contenedor_se_resuelve_por_el(): void
    {
        // Un modelo con dependencias en el constructor solo se puede construir
        // desde el contenedor; con `new` reventaria.
        $this->app->bind(TestModel::class, fn () => tap(new TestModel, function ($m): void {
            $m->setTable('test_models');
        }));

        $this->assertInstanceOf(
            Collection::class,
            $this->builder()->get(TestModel::class, ['paginate' => 0])
        );
    }

    #[Test]
    public function sin_filtros_resueltos_se_reconstruye_la_ruta_legada(): void
    {
        config()->set('search-surge.filters.defaults', []);

        $this->app->make(FilterRegistry::class)->forget();

        // TestUser no tiene filtros y, sin comunes, la lista queda vacia: el
        // Builder cae en la ruta legada para poblar $data.
        $query = $this->builder()->query(
            TestUser::class,
            ['paginate' => 0]
        );

        $this->assertInstanceOf(\Illuminate\Database\Eloquent\Builder::class, $query);
    }

    #[Test]
    public function el_conteo_del_evento_es_null_cuando_no_se_puede_contar(): void
    {
        // cursorPaginate no es Countable: el evento debe decir null, no fallar.
        Event::fake([SearchExecuted::class]);

        $this->builder()->get(TestModel::class, ['paginate' => 5], ['paginator' => 'cursor']);

        Event::assertDispatched(
            SearchExecuted::class,
            fn ($e): bool => $e->results === null || is_int($e->results)
        );
    }

    #[Test]
    public function el_tope_de_pagina_admite_null_para_no_acotar(): void
    {
        config()->set('search-surge.pagination.max_per_page', null);

        $result = $this->builder()->get(TestModel::class, ['paginate' => 5000]);

        $this->assertSame(5000, $result->perPage());
    }

    #[Test]
    public function basePath_se_arrastra_a_la_resolucion_de_filtros(): void
    {
        $filtros = $this->builder()
            ->setBasePath(realpath(__DIR__.'/../..').DIRECTORY_SEPARATOR)
            ->query(TestModel::class, [], [
                'filtersPath' => 'tests'.DIRECTORY_SEPARATOR.'Models'.DIRECTORY_SEPARATOR.'Filters',
                'filtersNamespace' => 'Innoboxrr\\SearchSurge\\Tests\\Models\\Filters',
            ]);

        $this->assertInstanceOf(\Illuminate\Database\Eloquent\Builder::class, $filtros);
    }

    /* -----------------------------------------------------------------
     | Comando explain: ramas de presentación
     | ----------------------------------------------------------------- */

    #[Test]
    public function el_explain_dice_cuando_no_hay_nada_que_avisar(): void
    {
        // Por clave primaria y con orden explicito: usa el indice y no le falta
        // el ORDER BY, asi que no hay nada que reprocharle.
        $this->artisan('search-surge:explain', [
            'model' => TestModel::class,
            '--data' => '{"id":1,"orderBy":"id","orderMode":"asc"}',
        ])->expectsOutputToContain('Sin avisos')
            ->assertSuccessful();
    }

    #[Test]
    public function el_explain_admite_datos_vacios(): void
    {
        $this->artisan('search-surge:explain', [
            'model' => TestModel::class,
            '--data' => '',
        ])->assertSuccessful();
    }

    #[Test]
    public function el_explain_rechaza_un_json_que_no_es_objeto(): void
    {
        $this->artisan('search-surge:explain', [
            'model' => TestModel::class,
            '--data' => '"solo un texto"',
        ])->assertFailed();
    }
}
