<?php

namespace Innoboxrr\SearchSurge\Tests\Unit;

use Illuminate\Support\Facades\DB;
use Innoboxrr\SearchSurge\Search\Builder;
use Innoboxrr\SearchSurge\Search\Support\DataContainer;
use Innoboxrr\SearchSurge\Search\Utils\CreationFilterQuery;
use Innoboxrr\SearchSurge\Search\Utils\Order;
use Innoboxrr\SearchSurge\Tests\Models\TestModel;
use Innoboxrr\SearchSurge\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

/**
 * Todo lo que entra por $data viene de la petición. Aquí se comprueba que
 * ninguna de esas superficies llega al SQL sin pasar por un binding o por una
 * lista blanca.
 */
class SecurityTest extends TestCase
{
    protected function builder(): Builder
    {
        return $this->app->make(Builder::class);
    }

    public static function cargas(): array
    {
        return [
            'union' => ["' UNION SELECT * FROM test_users --"],
            'drop' => ['1; DROP TABLE test_models;'],
            'or_true' => ["1' OR '1'='1"],
            'comentario' => ['1 -- '],
            'comilla' => ["O'Brien"],
            'backslash' => ['algo\\; DELETE FROM test_models'],
            'nulo' => ["a\0b"],
            'raw_sql' => ['(SELECT password FROM test_users LIMIT 1)'],
        ];
    }

    /* -----------------------------------------------------------------
     | Ordenamiento
     | ----------------------------------------------------------------- */

    #[Test]
    #[DataProvider('cargas')]
    public function orderBy_no_acepta_nada_que_no_sea_la_columna_declarada(string $payload): void
    {
        $query = Order::orderBy(TestModel::query(), new DataContainer([
            'orderBy' => $payload,
            'orderMode' => 'desc',
        ]), 'created_at');

        // El filtro declara 'created_at'; cualquier otra cosa no ordena nada.
        $this->assertStringNotContainsString('order by', strtolower($query->toSql()));
    }

    #[Test]
    #[DataProvider('cargas')]
    public function orderByAny_solo_acepta_columnas_de_la_lista_blanca(string $payload): void
    {
        $query = Order::orderByAny(TestModel::query(), new DataContainer([
            'orderBy' => $payload,
        ]), ['name', 'created_at']);

        $this->assertStringNotContainsString('order by', strtolower($query->toSql()));
    }

    #[Test]
    public function orderMode_solo_puede_ser_asc_o_desc(): void
    {
        foreach (['desc; DROP TABLE x', 'DESC--', 'asc)', '', null, 'RANDOM()'] as $mode) {
            $sql = Order::orderBy(TestModel::query(), new DataContainer([
                'orderBy' => 'name',
                'orderMode' => $mode,
            ]), 'name')->toSql();

            $this->assertMatchesRegularExpression(
                '/order by "name" (asc|desc)$/',
                $sql,
                'orderMode se colo en el SQL: ' . $sql
            );
        }
    }

    /* -----------------------------------------------------------------
     | Fechas
     | ----------------------------------------------------------------- */

    #[Test]
    #[DataProvider('cargas')]
    public function el_operador_de_fecha_solo_acepta_los_de_la_tabla(string $payload): void
    {
        $sql = CreationFilterQuery::sort(TestModel::query(), new DataContainer([
            'created_at' => '2026-01-15',
            'operator' => $payload,
        ]))->toSql();

        $this->assertStringNotContainsString('where', strtolower($sql));
    }

    #[Test]
    #[DataProvider('cargas')]
    public function una_fecha_maliciosa_no_llega_al_sql(string $payload): void
    {
        $query = CreationFilterQuery::sort(TestModel::query(), new DataContainer([
            'created_at' => $payload,
            'operator' => '>=',
        ]));

        $sql = $query->toSql();

        // O no filtra (fecha ilegible), o filtra con un binding.
        if (str_contains(strtolower($sql), 'where')) {
            $this->assertStringContainsString('?', $sql);
            $this->assertStringNotContainsString($payload, $sql);
        } else {
            $this->assertTrue(true);
        }
    }

    /* -----------------------------------------------------------------
     | Columnas
     | ----------------------------------------------------------------- */

    #[Test]
    #[DataProvider('cargas')]
    public function la_opcion_columns_rechaza_lo_que_no_sea_un_identificador(string $payload): void
    {
        $sql = $this->builder()
            ->query(TestModel::class, [], ['columns' => [$payload]])
            ->toSql();

        $this->assertStringContainsString('select *', $sql);
        $this->assertStringNotContainsString($payload, $sql);
    }

    #[Test]
    public function columns_no_se_lee_de_los_datos_de_la_peticion(): void
    {
        // Aceptar nombres de columna desde $data seria una via de inyeccion,
        // asi que solo se leen de $options, que las pone el desarrollador.
        $sql = $this->builder()
            ->query(TestModel::class, ['columns' => ['(select 1)'], 'fields' => ['x']])
            ->toSql();

        $this->assertStringContainsString('select * from', $sql);
    }

    #[Test]
    public function columns_acepta_tabla_punto_columna(): void
    {
        $sql = $this->builder()
            ->query(TestModel::class, [], ['columns' => ['test_models.id', 'name']])
            ->toSql();

        $this->assertStringContainsString('"test_models"."id"', $sql);
    }

    /* -----------------------------------------------------------------
     | Paginación
     | ----------------------------------------------------------------- */

    #[Test]
    #[DataProvider('cargas')]
    public function paginate_no_numerico_cae_al_valor_por_defecto(string $payload): void
    {
        $result = $this->builder()->get(TestModel::class, ['paginate' => $payload]);

        $this->assertSame(10, $result->perPage());
    }

    #[Test]
    #[DataProvider('cargas')]
    public function page_no_numerico_no_llega_al_sql(string $payload): void
    {
        DB::flushQueryLog();
        DB::enableQueryLog();

        $this->builder()->get(TestModel::class, ['paginate' => 10, 'page' => $payload]);

        foreach (DB::getQueryLog() as $entry) {
            $this->assertStringNotContainsString('DROP', strtoupper($entry['query']));
            $this->assertStringNotContainsString('UNION', strtoupper($entry['query']));
        }
    }

    #[Test]
    public function el_nombre_del_paginador_no_es_manipulable_a_algo_desconocido(): void
    {
        $result = $this->builder()->get(TestModel::class, [
            'paginate' => 10,
            'paginator' => 'raw; DROP TABLE test_models',
        ]);

        $this->assertInstanceOf(\Illuminate\Pagination\LengthAwarePaginator::class, $result);
    }

    /* -----------------------------------------------------------------
     | Resolución de clases
     | ----------------------------------------------------------------- */

    #[Test]
    public function un_namespace_de_filtros_manipulado_no_carga_clases_arbitrarias(): void
    {
        $filters = $this->app->make(\Innoboxrr\SearchSurge\Search\Support\FilterRegistry::class)
            ->resolve(TestModel::class, [
                'filters' => ['\\Illuminate\\Support\\Str', '\\stdClass', 'NoExiste'],
            ]);

        // Solo pasan clases que existen Y tienen apply(); Str tiene metodos
        // estaticos pero no apply.
        $this->assertSame([], $filters);
    }

    #[Test]
    public function una_ruta_de_filtros_con_traversal_no_carga_nada_de_fuera(): void
    {
        $filters = $this->app->make(\Innoboxrr\SearchSurge\Search\Support\FilterRegistry::class)
            ->resolve(TestModel::class, [
                'filtersPath' => '..' . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . '..',
                'filtersNamespace' => 'Whatever',
                'basePath' => sys_get_temp_dir() . DIRECTORY_SEPARATOR,
            ]);

        // El candidato con traversal no aporta nada -no hay clases cargables
        // ahi- y la resolucion cae a la convencion. Lo que se comprueba es que
        // no aparezca NADA fuera del namespace legitimo del modelo.
        foreach ($filters as $filter) {
            $this->assertStringStartsWith(
                'Innoboxrr\\SearchSurge\\Tests\\Models\\Filters\\TestModel\\',
                $filter,
                "Se resolvio un filtro fuera del namespace esperado: {$filter}"
            );
        }
    }

    #[Test]
    public function un_namespace_inventado_no_resuelve_ningun_filtro(): void
    {
        $filters = $this->app->make(\Innoboxrr\SearchSurge\Search\Support\FilterRegistry::class)
            ->resolve(\Innoboxrr\SearchSurge\Tests\Models\TestUser::class, [
                'filtersNamespace' => 'Vendor\\Que\\No\\Existe',
            ]);

        $this->assertSame([], $filters);
    }

    /* -----------------------------------------------------------------
     | Datos
     | ----------------------------------------------------------------- */

    #[Test]
    #[DataProvider('cargas')]
    public function los_valores_de_los_filtros_viajan_siempre_como_bindings(string $payload): void
    {
        $query = $this->builder()->query(TestModel::class, ['id' => $payload, 'name' => $payload]);

        $this->assertStringNotContainsString($payload, $query->toSql());
    }

    #[Test]
    public function un_whereIn_desde_una_lista_de_la_peticion_sigue_parametrizado(): void
    {
        $query = $this->builder()->query(TestModel::class, [
            'ids' => "1,2,3');DROP TABLE test_models;--",
        ]);

        $sql = $query->toSql();

        $this->assertStringNotContainsString('DROP', strtoupper($sql));

        if (str_contains($sql, 'in (')) {
            $this->assertMatchesRegularExpression('/in \(\?(, \?)*\)/', $sql);
        }
    }

    #[Test]
    public function la_base_sigue_intacta_despues_de_todas_las_cargas(): void
    {
        TestModel::query()->insert([['name' => 'intacto']]);

        foreach (self::cargas() as [$payload]) {
            $this->builder()->get(TestModel::class, [
                'paginate' => 0,
                'id' => $payload,
                'name' => $payload,
                'ids' => $payload,
                'orderBy' => $payload,
                'orderMode' => $payload,
                'created_at' => $payload,
                'operator' => $payload,
                'created_at_start_date' => $payload,
                'created_at_end_date' => $payload,
            ]);
        }

        $this->assertSame(1, TestModel::query()->count());
        $this->assertTrue(\Illuminate\Support\Facades\Schema::hasTable('test_models'));
        $this->assertTrue(\Illuminate\Support\Facades\Schema::hasTable('test_users'));
    }
}
