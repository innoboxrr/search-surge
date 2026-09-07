<?php

namespace Innoboxrr\SearchSurge\Tests\Unit;

use Illuminate\Support\Facades\File;
use Innoboxrr\SearchSurge\Facades\SearchSurge;
use Innoboxrr\SearchSurge\Search\Support\SearchSchema;
use Innoboxrr\SearchSurge\Tests\Fixtures\Filters\KeyedNameFilter;
use Innoboxrr\SearchSurge\Tests\Models\TestModel;
use Innoboxrr\SearchSurge\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

class SchemaAndMakeTest extends TestCase
{
    protected function schema(): SearchSchema
    {
        return $this->app->make(SearchSchema::class);
    }

    /* -----------------------------------------------------------------
     | Contrato de entrada
     | ----------------------------------------------------------------- */

    #[Test]
    public function el_esquema_lista_los_filtros_y_sus_parametros(): void
    {
        $schema = $this->schema()->for(TestModel::class);

        $this->assertSame(TestModel::class, $schema['model']);
        $this->assertNotEmpty($schema['filters']);
        $this->assertContains('created_at', $schema['parameters']);
        $this->assertContains('updated_at_start_date', $schema['parameters']);
        $this->assertArrayHasKey('paginate', $schema['control']);
    }

    #[Test]
    public function el_esquema_marca_los_filtros_criticos(): void
    {
        $schema = $this->schema()->for(TestModel::class);

        $managed = array_values(array_filter(
            $schema['filters'],
            static fn (array $f): bool => $f['name'] === 'ManagedFilter'
        ));

        $this->assertTrue($managed[0]['critical']);
        $this->assertSame(-100, $managed[0]['priority']);
    }

    #[Test]
    public function el_esquema_avisa_de_que_la_lista_no_esta_cerrada(): void
    {
        // TestModel tiene un IdFilter sin $keys: puede leer cualquier cosa, asi
        // que la lista de parametros no se puede dar por completa.
        $this->assertFalse($this->schema()->for(TestModel::class)['complete']);
    }

    #[Test]
    public function el_esquema_esta_completo_si_todos_los_filtros_declaran_keys(): void
    {
        $schema = $this->schema()->for(TestModel::class, ['filters' => [KeyedNameFilter::class]]);

        $this->assertTrue($schema['complete']);
        $this->assertSame(['name'], $schema['parameters']);
    }

    #[Test]
    public function parameters_junta_los_de_filtros_y_los_de_control(): void
    {
        $params = $this->schema()->parameters(TestModel::class, ['filters' => [KeyedNameFilter::class]]);

        $this->assertContains('name', $params);
        $this->assertContains('paginate', $params);
        $this->assertContains('orderBy', $params);
    }

    #[Test]
    public function detecta_parametros_que_nadie_va_a_leer(): void
    {
        // El caso real: escribir ?nombre=x en vez de ?name=x y no enterarte.
        $sobran = $this->schema()->unknown(
            TestModel::class,
            ['name' => 'ana', 'nombre' => 'ana', 'paginate' => 10],
            ['filters' => [KeyedNameFilter::class]]
        );

        $this->assertSame(['nombre'], $sobran);
    }

    #[Test]
    public function no_afirma_que_sobran_parametros_si_no_puede_saberlo(): void
    {
        // Con un filtro sin $keys, decir que un parametro sobra seria mentir.
        $sobran = $this->schema()->unknown(TestModel::class, ['loquesea' => 1]);

        $this->assertSame([], $sobran);
    }

    #[Test]
    public function el_facade_expone_el_esquema(): void
    {
        $this->assertArrayHasKey('parameters', SearchSurge::schema(TestModel::class));
        $this->assertSame(
            ['nombre'],
            SearchSurge::unknownParameters(
                TestModel::class,
                ['name' => 'a', 'nombre' => 'b'],
                ['filters' => [KeyedNameFilter::class]]
            )
        );
    }

    #[Test]
    public function el_comando_vuelca_el_esquema_como_json(): void
    {
        $this->artisan('search-surge:filters', [
            'model' => TestModel::class,
            '--json' => true,
        ])->assertSuccessful();
    }

    /* -----------------------------------------------------------------
     | Generador de filtros
     | ----------------------------------------------------------------- */

    protected function filtersDirectory(): string
    {
        return realpath(__DIR__ . '/../Models/Filters/TestModel');
    }

    protected function cleanUp(string $class): void
    {
        $path = $this->filtersDirectory() . DIRECTORY_SEPARATOR . $class . '.php';

        if (File::exists($path)) {
            File::delete($path);
        }
    }

    public static function tipos(): array
    {
        return [
            ['basic', 'Order::orderBy'],
            ['text', 'TextSearch::prefix'],
            ['date', 'DateFilterQuery::apply'],
            ['engine', 'extends EngineFilter'],
            ['managed', 'extends Managed'],
        ];
    }

    #[Test]
    #[DataProvider('tipos')]
    public function genera_cada_tipo_de_filtro(string $type, string $needle): void
    {
        $class = 'Generado' . ucfirst($type) . 'Filter';
        $this->cleanUp($class);

        $this->artisan('search-surge:filter', [
            'model' => TestModel::class,
            'name' => 'Generado' . ucfirst($type),
            '--type' => $type,
        ])->assertSuccessful();

        $path = $this->filtersDirectory() . DIRECTORY_SEPARATOR . $class . '.php';

        $this->assertFileExists($path);
        $this->assertStringContainsString($needle, File::get($path));
        $this->assertStringContainsString(
            'namespace Innoboxrr\\SearchSurge\\Tests\\Models\\Filters\\TestModel;',
            File::get($path)
        );

        $this->cleanUp($class);
    }

    #[Test]
    public function el_filtro_generado_es_php_valido_y_lo_encuentra_la_convencion(): void
    {
        $this->cleanUp('ColorFilter');

        $this->artisan('search-surge:filter', [
            'model' => TestModel::class,
            'name' => 'Color',
        ])->assertSuccessful();

        $path = $this->filtersDirectory() . DIRECTORY_SEPARATOR . 'ColorFilter.php';

        require_once $path;

        $class = 'Innoboxrr\\SearchSurge\\Tests\\Models\\Filters\\TestModel\\ColorFilter';

        $this->assertTrue(class_exists($class));
        $this->assertTrue(method_exists($class, 'apply'));

        SearchSurge::forgetFilters();

        $this->assertContains($class, SearchSurge::filtersFor(TestModel::class));

        $this->cleanUp('ColorFilter');
    }

    #[Test]
    public function no_duplica_el_sufijo_filter(): void
    {
        $this->cleanUp('TonoFilter');

        $this->artisan('search-surge:filter', [
            'model' => TestModel::class,
            'name' => 'TonoFilter',
        ])->assertSuccessful();

        $this->assertFileExists($this->filtersDirectory() . DIRECTORY_SEPARATOR . 'TonoFilter.php');
        $this->assertFileDoesNotExist($this->filtersDirectory() . DIRECTORY_SEPARATOR . 'TonoFilterFilter.php');

        $this->cleanUp('TonoFilter');
    }

    #[Test]
    public function no_sobrescribe_sin_force(): void
    {
        $this->artisan('search-surge:filter', [
            'model' => TestModel::class,
            'name' => 'Id',
        ])->assertFailed();

        // El IdFilter original sigue intacto.
        $this->assertStringContainsString(
            'Order::orderBy($query, $data, \'id\')',
            File::get($this->filtersDirectory() . DIRECTORY_SEPARATOR . 'IdFilter.php')
        );
    }

    #[Test]
    public function rechaza_un_modelo_inexistente(): void
    {
        $this->artisan('search-surge:filter', [
            'model' => 'App\\Models\\NoExiste',
            'name' => 'Algo',
        ])->assertFailed();
    }
}
