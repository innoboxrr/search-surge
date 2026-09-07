<?php

namespace Innoboxrr\SearchSurge\Tests\Unit;

use Illuminate\Support\Facades\DB;
use Innoboxrr\SearchSurge\Exceptions\PageLimitExceededException;
use Innoboxrr\SearchSurge\Search\Builder;
use Innoboxrr\SearchSurge\Search\Support\ComposerLocator;
use Innoboxrr\SearchSurge\Search\Support\FilterRegistry;
use Innoboxrr\SearchSurge\Tests\Models\TestModel;
use Innoboxrr\SearchSurge\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * Comportamiento de la paginación cuando la tabla deja de ser pequeña.
 *
 * Los tres problemas que se verifican aquí no se manifiestan con 20 filas de
 * fixture: aparecen cuando hay empates en la columna de orden, cuando el
 * COUNT(*) empieza a costar y cuando alguien pide la página 100.000.
 */
class ScaleTest extends TestCase
{
    protected function builder(): Builder
    {
        return $this->app->make(Builder::class);
    }

    /**
     * Filas con created_at repetido a propósito: es justo lo que rompe la
     * paginación cuando el ORDER BY no define un orden total.
     */
    protected function seedWithTiedTimestamps(int $count, int $distinctDates = 3): void
    {
        $rows = [];

        for ($i = 1; $i <= $count; $i++) {
            $day = str_pad((string) (($i % $distinctDates) + 1), 2, '0', STR_PAD_LEFT);

            $rows[] = [
                'name' => 'fila-'.str_pad((string) $i, 4, '0', STR_PAD_LEFT),
                'created_at' => "2026-01-{$day} 10:00:00",
                'updated_at' => "2026-01-{$day} 10:00:00",
            ];
        }

        foreach (array_chunk($rows, 200) as $chunk) {
            TestModel::query()->insert($chunk);
        }
    }

    /**
     * Recorre todas las páginas y devuelve los ids en el orden en que salieron.
     *
     * @return array<int, int>
     */
    protected function walkPages(int $perPage, array $data = [], array $options = []): array
    {
        $seen = [];
        $page = 1;

        do {
            $result = $this->builder()->get(
                TestModel::class,
                array_merge($data, ['paginate' => $perPage, 'page' => $page]),
                $options
            );

            foreach ($result->items() as $item) {
                $seen[] = $item->id;
            }

            $page++;
        } while ($result->hasMorePages() && $page < 100);

        return $seen;
    }

    /* -----------------------------------------------------------------
     | Orden estable
     | ----------------------------------------------------------------- */

    #[Test]
    public function la_paginacion_no_repite_ni_se_salta_filas_con_empates_en_el_orden(): void
    {
        $this->seedWithTiedTimestamps(120, distinctDates: 3);

        $ids = $this->walkPages(10, ['orderBy' => 'created_at', 'orderMode' => 'asc']);

        $this->assertCount(120, $ids, 'Se perdieron o repitieron filas al paginar.');
        $this->assertSame(120, count(array_unique($ids)), 'Hay ids duplicados entre paginas.');
    }

    #[Test]
    public function anade_la_clave_primaria_como_desempate_cuando_ya_hay_orden(): void
    {
        $sql = $this->builder()
            ->query(TestModel::class, ['orderBy' => 'created_at', 'orderMode' => 'desc'])
            ->toSql();

        $this->assertSqlHas('order by created_at desc', $sql);
        $this->assertSqlHas('test_models.id desc', $sql);
    }

    #[Test]
    public function no_impone_un_orden_cuando_la_consulta_no_tenia_ninguno(): void
    {
        // Imponer ORDER BY donde no lo habia puede cambiar el plan de ejecucion,
        // asi que solo se anade el desempate si ya se estaba ordenando.
        $sql = $this->builder()->query(TestModel::class)->toSql();

        $this->assertStringNotContainsString('order by', $sql);
    }

    #[Test]
    public function no_duplica_el_desempate_si_ya_se_ordena_por_la_clave(): void
    {
        $sql = $this->builder()
            ->query(TestModel::class, ['orderBy' => 'id', 'orderMode' => 'asc'])
            ->toSql();

        $this->assertSame(1, $this->sqlCount('id', $sql), 'La clave se anadio dos veces al ORDER BY.');
    }

    #[Test]
    public function el_desempate_se_puede_desactivar(): void
    {
        config()->set('search-surge.pagination.stable_order', false);

        $sql = $this->builder()
            ->query(TestModel::class, ['orderBy' => 'created_at'])
            ->toSql();

        $this->assertSqlMissing('test_models.id', $sql);
    }

    #[Test]
    public function el_desempate_respeta_el_sentido_del_orden_principal(): void
    {
        $sql = $this->builder()
            ->query(TestModel::class, ['orderBy' => 'created_at', 'orderMode' => 'desc'])
            ->toSql();

        $this->assertSqlHas('test_models.id desc', $sql);
    }

    /* -----------------------------------------------------------------
     | Caché del conteo
     | ----------------------------------------------------------------- */

    #[Test]
    public function sin_cache_de_conteo_cada_pagina_ejecuta_su_propio_count(): void
    {
        $this->seedWithTiedTimestamps(30);

        DB::flushQueryLog();
        DB::enableQueryLog();

        $this->builder()->get(TestModel::class, ['paginate' => 10]);
        $this->builder()->get(TestModel::class, ['paginate' => 10]);

        $counts = $this->countQueriesOfType('count(*)');

        $this->assertSame(2, $counts);
    }

    #[Test]
    public function con_cache_de_conteo_el_count_solo_se_ejecuta_una_vez(): void
    {
        config()->set('search-surge.pagination.count_cache', 60);
        $this->seedWithTiedTimestamps(30);

        DB::flushQueryLog();
        DB::enableQueryLog();

        $a = $this->builder()->get(TestModel::class, ['paginate' => 10]);
        $b = $this->builder()->get(TestModel::class, ['paginate' => 10]);

        $this->assertSame(1, $this->countQueriesOfType('count(*)'));
        $this->assertSame(30, $a->total());
        $this->assertSame(30, $b->total());
    }

    #[Test]
    public function la_cache_de_conteo_distingue_filtros_distintos(): void
    {
        config()->set('search-surge.pagination.count_cache', 60);
        $this->seedWithTiedTimestamps(30, distinctDates: 3);

        $todos = $this->builder()->get(TestModel::class, ['paginate' => 10]);

        $filtrado = $this->builder()->get(TestModel::class, [
            'paginate' => 10,
            'created_at' => '2026-01-01',
            'operator' => '==',
        ]);

        $this->assertSame(30, $todos->total());
        $this->assertNotSame(30, $filtrado->total(), 'La cache mezclo dos consultas distintas.');
    }

    #[Test]
    public function la_cache_de_conteo_devuelve_un_paginador_equivalente(): void
    {
        $this->seedWithTiedTimestamps(45);

        $sinCache = $this->builder()->get(TestModel::class, ['paginate' => 10, 'page' => 2]);

        config()->set('search-surge.pagination.count_cache', 60);

        $conCache = $this->builder()->get(TestModel::class, ['paginate' => 10, 'page' => 2]);

        $this->assertSame($sinCache->total(), $conCache->total());
        $this->assertSame($sinCache->lastPage(), $conCache->lastPage());
        $this->assertSame($sinCache->currentPage(), $conCache->currentPage());
        $this->assertSame($sinCache->perPage(), $conCache->perPage());
        $this->assertSame(
            $sinCache->pluck('id')->all(),
            $conCache->pluck('id')->all()
        );
    }

    #[Test]
    public function con_cero_resultados_la_cache_de_conteo_ni_consulta_las_filas(): void
    {
        config()->set('search-surge.pagination.count_cache', 60);

        $result = $this->builder()->get(TestModel::class, ['paginate' => 10]);

        $this->assertSame(0, $result->total());
        $this->assertCount(0, $result->items());
    }

    /* -----------------------------------------------------------------
     | Profundidad de paginación
     | ----------------------------------------------------------------- */

    #[Test]
    public function sin_tope_se_puede_pedir_cualquier_pagina(): void
    {
        $result = $this->builder()->get(TestModel::class, ['paginate' => 10, 'page' => 50000]);

        $this->assertSame(50000, $result->currentPage());
    }

    #[Test]
    public function con_tope_una_pagina_demasiado_profunda_se_rechaza(): void
    {
        config()->set('search-surge.pagination.max_page', 1000);

        $this->expectException(PageLimitExceededException::class);

        $this->builder()->get(TestModel::class, ['paginate' => 10, 'page' => 1001]);
    }

    #[Test]
    public function el_tope_de_pagina_deja_pasar_el_limite_exacto(): void
    {
        config()->set('search-surge.pagination.max_page', 1000);

        $result = $this->builder()->get(TestModel::class, ['paginate' => 10, 'page' => 1000]);

        $this->assertSame(1000, $result->currentPage());
    }

    #[Test]
    public function la_excepcion_de_pagina_lleva_el_contexto_para_renderizar_un_400(): void
    {
        config()->set('search-surge.pagination.max_page', 50);

        try {
            $this->builder()->get(TestModel::class, ['paginate' => 10, 'page' => 99]);
            $this->fail('Deberia haber lanzado.');
        } catch (PageLimitExceededException $e) {
            $this->assertSame(99, $e->page);
            $this->assertSame(50, $e->maxPage);
            $this->assertSame(400, $e->getStatusCode());
        }
    }

    #[Test]
    public function una_pagina_negativa_o_cero_se_normaliza_a_la_primera(): void
    {
        $this->seedWithTiedTimestamps(15);

        foreach ([0, -1, '-5'] as $page) {
            $result = $this->builder()->get(TestModel::class, ['paginate' => 10, 'page' => $page]);

            $this->assertSame(1, $result->currentPage());
        }
    }

    /* -----------------------------------------------------------------
     | Recorridos sin cargar todo en memoria
     | ----------------------------------------------------------------- */

    #[Test]
    public function lazy_recorre_todas_las_filas_en_lotes(): void
    {
        $this->seedWithTiedTimestamps(250);

        DB::flushQueryLog();
        DB::enableQueryLog();

        $ids = $this->builder()->lazy(TestModel::class, [], [], 100)->pluck('id')->all();

        $this->assertCount(250, $ids);
        $this->assertSame(250, count(array_unique($ids)));

        // 250 filas en lotes de 100 son 3 lotes con datos mas uno vacio.
        $this->assertLessThanOrEqual(4, count(DB::getQueryLog()));
    }

    #[Test]
    public function lazyById_recorre_todo_sin_usar_offset(): void
    {
        $this->seedWithTiedTimestamps(250);

        DB::flushQueryLog();
        DB::enableQueryLog();

        $ids = $this->builder()->lazyById(TestModel::class, [], [], 100)->pluck('id')->all();

        $this->assertCount(250, $ids);

        foreach (DB::getQueryLog() as $entry) {
            $this->assertStringNotContainsString('offset', strtolower($entry['query']));
        }
    }

    #[Test]
    public function cursor_ejecuta_una_sola_consulta(): void
    {
        $this->seedWithTiedTimestamps(120);

        DB::flushQueryLog();
        DB::enableQueryLog();

        $ids = $this->builder()->cursor(TestModel::class)->pluck('id')->all();

        $this->assertCount(120, $ids);
        $this->assertCount(1, DB::getQueryLog());
    }

    #[Test]
    public function la_paginacion_por_cursor_recorre_cada_fila_exactamente_una_vez(): void
    {
        $this->seedWithTiedTimestamps(95, distinctDates: 2);

        $seen = [];
        $cursor = null;

        for ($i = 0; $i < 50; $i++) {
            $result = $this->builder()->get(
                TestModel::class,
                array_filter(['paginate' => 10, 'cursor' => $cursor]),
                ['paginator' => 'cursor']
            );

            foreach ($result->items() as $item) {
                $seen[] = $item->id;
            }

            if (! $result->hasMorePages()) {
                break;
            }

            $cursor = $result->nextCursor()->encode();
        }

        $this->assertCount(95, $seen);
        $this->assertSame(95, count(array_unique($seen)));
    }

    #[Test]
    public function el_paginador_simple_no_ejecuta_ningun_count(): void
    {
        $this->seedWithTiedTimestamps(50);

        DB::flushQueryLog();
        DB::enableQueryLog();

        $this->builder()->get(TestModel::class, ['paginate' => 10], ['paginator' => 'simple']);

        $this->assertSame(0, $this->countQueriesOfType('count(*)'));
    }

    #[Test]
    public function el_paginador_por_cursor_no_ejecuta_ningun_count(): void
    {
        $this->seedWithTiedTimestamps(50);

        DB::flushQueryLog();
        DB::enableQueryLog();

        $this->builder()->get(TestModel::class, ['paginate' => 10], ['paginator' => 'cursor']);

        $this->assertSame(0, $this->countQueriesOfType('count(*)'));
    }

    /* -----------------------------------------------------------------
     | Coste de resolver los filtros
     | ----------------------------------------------------------------- */

    #[Test]
    public function resolver_los_filtros_no_toca_el_disco_dos_veces(): void
    {
        $registry = $this->app->make(FilterRegistry::class);

        $primera = $registry->resolve(TestModel::class);

        // La segunda llamada debe salir de la memoizacion, no de un glob nuevo.
        $antes = $this->globCallCount();
        $segunda = $registry->resolve(TestModel::class);

        $this->assertSame($primera, $segunda);
        $this->assertSame($antes, $this->globCallCount());
    }

    protected function countQueriesOfType(string $needle): int
    {
        return count(array_filter(
            DB::getQueryLog(),
            static fn (array $entry): bool => str_contains(strtolower($entry['query']), $needle)
        ));
    }

    /**
     * Proxy barato: cuantas veces se ha resuelto un directorio. No hay hook
     * para glob(), asi que se usa el estado interno del localizador.
     */
    protected function globCallCount(): int
    {
        $reflection = new \ReflectionClass(ComposerLocator::class);
        $property = $reflection->getProperty('resolved');

        return count($property->getValue());
    }
}
