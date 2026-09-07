<?php

namespace Innoboxrr\SearchSurge\Tests\Unit;

use Illuminate\Support\Facades\File;
use Innoboxrr\SearchSurge\Search\Support\FilterRegistry;
use Innoboxrr\SearchSurge\Tests\Fixtures\Filters\KeyedNameFilter;
use Innoboxrr\SearchSurge\Tests\Models\Filters\TestModel\IdFilter;
use Innoboxrr\SearchSurge\Tests\Models\TestModel;
use Innoboxrr\SearchSurge\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

class ConsoleTest extends TestCase
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

    #[Test]
    public function el_comando_de_cache_escribe_un_manifiesto_valido(): void
    {
        $this->artisan('search-surge:cache', [
            '--namespace' => ['Innoboxrr\\SearchSurge\\Tests\\Models'],
        ])->assertSuccessful();

        $path = $this->manifestPath();

        $this->assertFileExists($path);

        $manifest = require $path;

        $this->assertIsArray($manifest);
        $this->assertArrayHasKey(TestModel::class, $manifest);
        $this->assertContains(
            IdFilter::class,
            $manifest[TestModel::class]
        );
    }

    #[Test]
    public function el_manifiesto_evita_volver_a_tocar_el_disco(): void
    {
        $this->artisan('search-surge:cache', [
            '--namespace' => ['Innoboxrr\\SearchSurge\\Tests\\Models'],
        ])->assertSuccessful();

        // Registro nuevo, sin memoizacion: debe leer del manifiesto.
        $registry = new FilterRegistry(
            $this->app,
            $this->app->make('config'),
            $this->app->make('cache')
        );

        $this->assertArrayHasKey(TestModel::class, $registry->manifest());
        $this->assertNotEmpty($registry->resolve(TestModel::class));
    }

    #[Test]
    public function el_comando_de_limpieza_borra_el_manifiesto(): void
    {
        $this->artisan('search-surge:cache', [
            '--namespace' => ['Innoboxrr\\SearchSurge\\Tests\\Models'],
        ])->assertSuccessful();

        $this->assertFileExists($this->manifestPath());

        $this->artisan('search-surge:clear')->assertSuccessful();

        $this->assertFileDoesNotExist($this->manifestPath());
    }

    #[Test]
    public function el_comando_de_limpieza_no_falla_si_no_hay_manifiesto(): void
    {
        $this->artisan('search-surge:clear')->assertSuccessful();
    }

    #[Test]
    public function el_comando_de_listado_muestra_los_filtros_de_un_modelo(): void
    {
        $this->artisan('search-surge:filters', ['model' => TestModel::class])
            ->expectsOutputToContain('IdFilter')
            ->assertSuccessful();
    }

    #[Test]
    public function el_comando_de_listado_avisa_si_la_clase_no_existe(): void
    {
        $this->artisan('search-surge:filters', ['model' => 'App\\Models\\NoExiste'])
            ->assertFailed();
    }

    #[Test]
    public function el_manifiesto_recoge_lo_registrado_a_mano(): void
    {
        $this->app->make(FilterRegistry::class)->register(TestModel::class, [KeyedNameFilter::class]);

        $this->artisan('search-surge:cache')->assertSuccessful();

        $manifest = require $this->manifestPath();

        $this->assertContains(KeyedNameFilter::class, $manifest[TestModel::class]);
    }
}
