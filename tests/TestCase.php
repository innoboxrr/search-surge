<?php

namespace Innoboxrr\SearchSurge\Tests;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Innoboxrr\SearchSurge\Providers\SearchSurgeServiceProvider;
use Innoboxrr\SearchSurge\Search\Support\ComposerLocator;
use Innoboxrr\SearchSurge\Search\Support\FilterMeta;
use Innoboxrr\SearchSurge\Search\Support\FilterRegistry;
use Innoboxrr\SearchSurge\Tests\Fixtures\Filters\KeyedNameFilter;
use Innoboxrr\SearchSurge\Tests\Fixtures\Filters\LateFilter;

abstract class TestCase extends \Orchestra\Testbench\TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->createSchema();

        // El estado estático de las fixtures y las memoizaciones no deben
        // filtrarse de un test al siguiente.
        KeyedNameFilter::$calls = 0;
        LateFilter::$order = [];
        FilterMeta::flush();
        ComposerLocator::flush();
        $this->app->make(FilterRegistry::class)->forget();
    }

    protected function createSchema(): void
    {
        Schema::create('test_models', function (Blueprint $table): void {
            $table->id();
            $table->string('name')->nullable();
            $table->unsignedBigInteger('owner_id')->nullable();
            $table->timestamps();
        });

        Schema::create('test_users', function (Blueprint $table): void {
            $table->id();
            $table->string('name')->nullable();
            $table->timestamps();
        });
    }

    protected function getPackageProviders($app): array
    {
        return [SearchSurgeServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);

        // En los tests queremos ver los filtros nuevos al instante.
        $app['config']->set('search-surge.cache.enabled', false);
    }

    /**
     * El SQL con los bindings ya interpolados, para poder afirmar sobre la
     * forma real de la consulta.
     */
    protected function rawSql(\Illuminate\Database\Eloquent\Builder $query): string
    {
        $sql = $query->toSql();

        foreach ($query->getBindings() as $binding) {
            $sql = preg_replace('/\?/', "'" . (string) $binding . "'", $sql, 1);
        }

        return $sql;
    }
}
