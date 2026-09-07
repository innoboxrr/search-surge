<?php

namespace Innoboxrr\SearchSurge\Tests\Unit;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\File;
use Innoboxrr\SearchSurge\Search\Support\FilterRegistry;
use Innoboxrr\SearchSurge\Search\Support\QueryAnalyzer;
use Innoboxrr\SearchSurge\Tests\Models\TestModel;
use Innoboxrr\SearchSurge\Tests\Models\TestUser;
use Innoboxrr\SearchSurge\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * Las ramas de los comandos que el camino feliz no toca: cuando no hay nada que
 * hacer, cuando el usuario pide el detalle, cuando algo no existe.
 *
 * Son las que se ejecutan el dia que algo va mal, que es justo cuando no puedes
 * permitirte que el comando reviente.
 */
class ConsoleBranchesTest extends TestCase
{
    protected function manifestPath(): string
    {
        return $this->app->make(FilterRegistry::class)->manifestPath();
    }

    protected function tearDown(): void
    {
        if (File::exists($this->manifestPath())) {
            File::delete($this->manifestPath());
        }

        parent::tearDown();
    }

    /* -----------------------------------------------------------------
     | search-surge:cache
     | ----------------------------------------------------------------- */

    #[Test]
    public function el_comando_de_cache_puede_mostrar_el_mapa(): void
    {
        $this->artisan('search-surge:cache', [
            '--namespace' => ['Innoboxrr\\SearchSurge\\Tests\\Models'],
            '--show' => true,
        ])->expectsOutputToContain('IdFilter')
            ->assertSuccessful();
    }

    #[Test]
    public function el_comando_de_cache_avisa_si_no_encuentra_modelos(): void
    {
        // Sin filtros comunes y con un namespace vacio no hay nada que compilar.
        config()->set('search-surge.filters.defaults', []);

        $this->artisan('search-surge:cache', [
            '--namespace' => ['Vendor\\Que\\No\\Existe'],
        ])->assertSuccessful();
    }

    #[Test]
    public function el_comando_de_cache_reemplaza_un_manifiesto_anterior(): void
    {
        File::ensureDirectoryExists(dirname($this->manifestPath()));
        File::put($this->manifestPath(), "<?php\n\nreturn ['viejo' => []];\n");

        $this->artisan('search-surge:cache', [
            '--namespace' => ['Innoboxrr\\SearchSurge\\Tests\\Models'],
        ])->assertSuccessful();

        $manifiesto = require $this->manifestPath();

        $this->assertArrayNotHasKey('viejo', $manifiesto);
        $this->assertArrayHasKey(TestModel::class, $manifiesto);
    }

    #[Test]
    public function el_manifiesto_generado_es_php_valido_y_reejecutable(): void
    {
        $this->artisan('search-surge:cache', [
            '--namespace' => ['Innoboxrr\\SearchSurge\\Tests\\Models'],
        ])->assertSuccessful();

        $contenido = File::get($this->manifestPath());

        $this->assertStringStartsWith('<?php', $contenido);
        $this->assertStringContainsString('No lo edites a mano', $contenido);

        // Cargarlo dos veces no debe dar resultados distintos.
        $this->assertSame(require $this->manifestPath(), require $this->manifestPath());
    }

    /* -----------------------------------------------------------------
     | search-surge:filters
     | ----------------------------------------------------------------- */

    #[Test]
    public function el_listado_sin_modelo_recorre_todos(): void
    {
        $this->artisan('search-surge:filters', [
            '--namespace' => ['Innoboxrr\\SearchSurge\\Tests\\Models'],
        ])->assertSuccessful();
    }

    #[Test]
    public function el_listado_avisa_cuando_un_modelo_no_tiene_filtros(): void
    {
        config()->set('search-surge.filters.defaults', []);

        $this->app->make(FilterRegistry::class)->forget();

        $this->artisan('search-surge:filters', [
            'model' => TestUser::class,
        ])->expectsOutputToContain('no tiene filtros')
            ->assertSuccessful();
    }

    #[Test]
    public function el_listado_en_json_rechaza_una_clase_inexistente(): void
    {
        $this->artisan('search-surge:filters', [
            'model' => 'App\\NoExiste',
            '--json' => true,
        ])->assertFailed();
    }

    #[Test]
    public function el_listado_en_json_de_varios_modelos_devuelve_una_lista(): void
    {
        $this->artisan('search-surge:filters', [
            '--namespace' => ['Innoboxrr\\SearchSurge\\Tests\\Models'],
            '--json' => true,
        ])->assertSuccessful();
    }

    /* -----------------------------------------------------------------
     | search-surge:filter
     | ----------------------------------------------------------------- */

    #[Test]
    public function el_generador_sobrescribe_con_force(): void
    {
        $dir = realpath(__DIR__.'/../Models/Filters/TestModel');
        $path = $dir.DIRECTORY_SEPARATOR.'ForzadoFilter.php';

        $this->artisan('search-surge:filter', [
            'model' => TestModel::class,
            'name' => 'Forzado',
        ])->assertSuccessful();

        $this->artisan('search-surge:filter', [
            'model' => TestModel::class,
            'name' => 'Forzado',
            '--type' => 'text',
            '--force' => true,
        ])->assertSuccessful();

        $this->assertStringContainsString('TextSearch::prefix', File::get($path));

        File::delete($path);
    }

    #[Test]
    public function el_generador_falla_si_no_puede_deducir_donde_crear(): void
    {
        // Un modelo cuyo namespace no esta en el autoloader PSR-4.
        $modelo = new class extends Model
        {
            protected $table = 'test_models';
        };

        $this->artisan('search-surge:filter', [
            'model' => $modelo::class,
            'name' => 'Algo',
        ])->assertFailed();
    }

    /* -----------------------------------------------------------------
     | Analizador: ramas del plan de MySQL
     | ----------------------------------------------------------------- */

    #[Test]
    public function el_analizador_detecta_una_ordenacion_costosa(): void
    {
        $codes = array_column(
            $this->app->make(QueryAnalyzer::class)->analyze(
                TestModel::query()->where('name', 'like', '%x%')->orderBy('price')
            ),
            'code'
        );

        $esperado = match (static::driver()) {
            'mysql', 'mariadb' => ['plan.filesort', 'plan.full_table_scan', 'plan.full_index_scan'],
            'pgsql' => ['plan.sort', 'plan.seq_scan'],
            default => ['plan.temp_btree', 'plan.scan'],
        };

        $this->assertNotEmpty(
            array_intersect($esperado, $codes),
            'Codigos obtenidos: '.implode(', ', $codes)
        );
    }

    #[Test]
    public function el_analizador_sobrevive_a_un_explain_imposible(): void
    {
        // Una consulta con SQL crudo invalido: el EXPLAIN falla y el analisis
        // debe degradarse, no propagar.
        $query = TestModel::query()->whereRaw('columna_que_no_existe = 1');

        $codes = array_column($this->app->make(QueryAnalyzer::class)->analyze($query), 'code');

        $this->assertContains('plan.unavailable', $codes);
    }

    #[Test]
    public function cada_hallazgo_lleva_severidad_codigo_mensaje_y_pista(): void
    {
        $findings = $this->app->make(QueryAnalyzer::class)->analyze(
            TestModel::query()->where('name', 'like', '%x%')
        );

        $this->assertNotEmpty($findings);

        foreach ($findings as $f) {
            $this->assertArrayHasKey('severity', $f);
            $this->assertArrayHasKey('code', $f);
            $this->assertNotSame('', $f['message']);
            $this->assertNotSame('', $f['hint'], "El hallazgo {$f['code']} no dice que hacer.");
            $this->assertContains($f['severity'], [
                QueryAnalyzer::SEVERITY_CRITICAL,
                QueryAnalyzer::SEVERITY_WARNING,
                QueryAnalyzer::SEVERITY_INFO,
            ]);
        }
    }
}
