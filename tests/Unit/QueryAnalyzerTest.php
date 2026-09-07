<?php

namespace Innoboxrr\SearchSurge\Tests\Unit;

use Illuminate\Support\Facades\Schema;
use Innoboxrr\SearchSurge\Search\Support\DataContainer;
use Innoboxrr\SearchSurge\Search\Support\QueryAnalyzer;
use Innoboxrr\SearchSurge\Search\Utils\CreationFilterQuery;
use Innoboxrr\SearchSurge\Search\Utils\TextSearch;
use Innoboxrr\SearchSurge\Tests\Models\TestModel;
use Innoboxrr\SearchSurge\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

class QueryAnalyzerTest extends TestCase
{
    protected function analyzer(): QueryAnalyzer
    {
        return $this->app->make(QueryAnalyzer::class);
    }

    /**
     * @return array<int, string>
     */
    protected function codes($query): array
    {
        return array_column($this->analyzer()->analyze($query), 'code');
    }

    /* -----------------------------------------------------------------
     | Detección estática
     | ----------------------------------------------------------------- */

    #[Test]
    public function detecta_un_like_con_comodin_por_delante(): void
    {
        $query = TextSearch::contains(
            TestModel::query(),
            new DataContainer(['q' => 'zapato']),
            'q',
            ['name']
        );

        $this->assertContains('like.leading_wildcard', $this->codes($query));
    }

    #[Test]
    public function no_avisa_de_un_like_por_prefijo(): void
    {
        $query = TextSearch::prefix(
            TestModel::query(),
            new DataContainer(['q' => 'zapato']),
            'q',
            ['name']
        );

        $this->assertNotContains('like.leading_wildcard', $this->codes($query));
    }

    #[Test]
    public function detecta_el_or_de_like_entre_columnas(): void
    {
        $query = TextSearch::contains(
            TestModel::query(),
            new DataContainer(['q' => 'zapato']),
            'q',
            ['name', 'owner_id']
        );

        $this->assertContains('like.or_across_columns', $this->codes($query));
    }

    #[Test]
    public function detecta_una_funcion_envolviendo_una_columna(): void
    {
        // Justo lo que hacia whereDate() antes de v3.
        $query = TestModel::query()->whereDate('created_at', '>=', '2026-01-01');

        $this->assertContains('sql.function_on_column', $this->codes($query));
    }

    #[Test]
    public function el_filtro_de_fechas_de_v3_no_dispara_ese_aviso(): void
    {
        $query = CreationFilterQuery::sort(
            TestModel::query(),
            new DataContainer(['created_at' => '2026-01-01', 'operator' => '>='])
        );

        $this->assertNotContains('sql.function_on_column', $this->codes($query));
    }

    #[Test]
    public function avisa_de_la_falta_de_order_by(): void
    {
        $this->assertContains('order.missing', $this->codes(TestModel::query()));
    }

    #[Test]
    public function no_avisa_si_hay_order_by(): void
    {
        $this->assertNotContains('order.missing', $this->codes(TestModel::query()->orderBy('id')));
    }

    #[Test]
    public function la_severidad_sube_con_varios_like(): void
    {
        $query = TextSearch::contains(
            TestModel::query(),
            new DataContainer(['q' => 'a b c']),
            'q',
            ['name']
        );

        $findings = array_values(array_filter(
            $this->analyzer()->analyze($query),
            static fn (array $f): bool => $f['code'] === 'like.leading_wildcard'
        ));

        $this->assertSame(QueryAnalyzer::SEVERITY_CRITICAL, $findings[0]['severity']);
    }

    /* -----------------------------------------------------------------
     | Plan de ejecución
     | ----------------------------------------------------------------- */

    #[Test]
    public function detecta_un_scan_completo_de_tabla(): void
    {
        $codes = $this->codes(TestModel::query()->where('name', 'like', '%x%'));

        // Cada motor nombra el hallazgo a su manera, pero los tres tienen que
        // darse cuenta de que esta consulta recorre la tabla entera.
        $esperado = match (static::driver()) {
            'mysql', 'mariadb' => ['plan.full_table_scan', 'plan.full_index_scan'],
            'pgsql' => ['plan.seq_scan'],
            default => ['plan.scan'],
        };

        $this->assertNotEmpty(
            array_intersect($esperado, $codes),
            'El analizador no vio el scan. Codigos: '.implode(', ', $codes)
        );
    }

    #[Test]
    public function no_avisa_de_scan_cuando_puede_usar_un_indice(): void
    {
        Schema::table('test_models', function ($table): void {
            $table->index('owner_id', 'idx_owner');
        });

        $codes = $this->codes(TestModel::query()->where('owner_id', 3));

        $this->assertNotContains('plan.scan', $codes);
    }

    #[Test]
    public function el_explain_devuelve_el_plan_del_motor(): void
    {
        $rows = $this->analyzer()->explain(TestModel::query()->where('id', 1));

        $this->assertNotEmpty($rows);

        // La forma del plan es cosa de cada motor; lo que se comprueba es que
        // la sintaxis del EXPLAIN es la correcta y devuelve algo utilizable.
        $clave = match (static::driver()) {
            'mysql', 'mariadb' => 'table',
            'pgsql' => 'QUERY PLAN',
            default => 'detail',
        };

        $this->assertArrayHasKey($clave, $rows[0]);
    }

    #[Test]
    public function el_analisis_no_revienta_con_una_consulta_rara(): void
    {
        $query = TestModel::query()
            ->selectRaw('count(*) as total')
            ->groupBy('owner_id')
            ->havingRaw('count(*) > ?', [1]);

        $this->assertIsArray($this->analyzer()->analyze($query));
    }

    /* -----------------------------------------------------------------
     | SQL legible
     | ----------------------------------------------------------------- */

    #[Test]
    public function interpola_los_bindings_para_mostrarlos(): void
    {
        $sql = QueryAnalyzer::toRawSql(
            TestModel::query()->where('name', 'ana')->where('owner_id', 7)
        );

        $this->assertStringContainsString("'ana'", $sql);
        $this->assertStringContainsString('7', $sql);
        $this->assertStringNotContainsString('?', $sql);
    }

    #[Test]
    public function escapa_las_comillas_al_interpolar(): void
    {
        $sql = QueryAnalyzer::toRawSql(TestModel::query()->where('name', "O'Brien"));

        $this->assertStringContainsString("'O''Brien'", $sql);
    }

    #[Test]
    public function no_confunde_un_binding_con_una_referencia_de_regex(): void
    {
        // Un valor con $ o \1 rompia la interpolacion si se usa preg_replace
        // sin escapar el reemplazo.
        $sql = QueryAnalyzer::toRawSql(TestModel::query()->where('name', '$1 y \\0'));

        $this->assertStringContainsString('$1', $sql);
    }

    /* -----------------------------------------------------------------
     | El comando
     | ----------------------------------------------------------------- */

    #[Test]
    public function el_comando_analiza_un_modelo(): void
    {
        $this->artisan('search-surge:explain', ['model' => TestModel::class])
            ->expectsOutputToContain('SQL')
            ->assertSuccessful();
    }

    #[Test]
    public function el_comando_falla_si_encuentra_algo_critico(): void
    {
        // Para poder usarlo en CI: lo critico rompe la salida, los avisos no.
        // created_at no tiene indice en el esquema de test, asi que filtrar por
        // el produce un scan con WHERE, que si es un hallazgo critico.
        $this->artisan('search-surge:explain', [
            'model' => TestModel::class,
            '--data' => '{"created_at_start_date":"2026-01-01","created_at_end_date":"2026-12-31"}',
        ])->assertFailed();
    }

    #[Test]
    public function un_scan_sin_where_no_se_reporta(): void
    {
        // Una consulta sin condiciones tiene que recorrer la tabla: avisar de
        // eso solo genera ruido y devalua los avisos de verdad.
        $this->assertNotContains('plan.scan', $this->codes(TestModel::query()));
    }

    #[Test]
    public function el_comando_rechaza_una_clase_inexistente(): void
    {
        $this->artisan('search-surge:explain', ['model' => 'App\\NoExiste'])
            ->assertFailed();
    }

    #[Test]
    public function el_comando_rechaza_un_json_invalido(): void
    {
        $this->artisan('search-surge:explain', [
            'model' => TestModel::class,
            '--data' => '{no soy json}',
        ])->assertFailed();
    }

    #[Test]
    public function el_comando_puede_mostrar_solo_el_sql(): void
    {
        $this->skipUnlessSqlite();

        $this->artisan('search-surge:explain', [
            'model' => TestModel::class,
            '--sql' => true,
        ])->expectsOutputToContain('select * from "test_models"')
            ->assertSuccessful();
    }

    #[Test]
    public function el_comando_mide_cuando_se_le_pide(): void
    {
        TestModel::query()->insert([['name' => 'ana']]);

        $this->artisan('search-surge:explain', [
            'model' => TestModel::class,
            '--time' => true,
        ])->expectsOutputToContain('Medicion real')
            ->assertSuccessful();
    }

    #[Test]
    public function el_comando_indica_que_filtros_se_omiten(): void
    {
        $this->artisan('search-surge:explain', ['model' => TestModel::class])
            ->expectsOutputToContain('omitido')
            ->assertSuccessful();
    }
}
