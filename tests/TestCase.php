<?php

namespace Innoboxrr\SearchSurge\Tests;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
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
        // SQLite en memoria nace vacio en cada test, pero MySQL y PostgreSQL
        // conservan las tablas entre uno y otro.
        foreach (['test_models', 'test_users', 'test_authors', 'test_codigos', 'solo_fechas'] as $table) {
            Schema::dropIfExists($table);
        }

        Schema::create('test_models', function (Blueprint $table): void {
            $table->id();
            $table->string('name')->nullable();
            $table->unsignedBigInteger('owner_id')->nullable();
            $table->unsignedBigInteger('author_id')->nullable();
            $table->string('status')->nullable();
            $table->decimal('price', 10, 2)->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('test_users', function (Blueprint $table): void {
            $table->id();
            $table->string('name')->nullable();
            $table->timestamps();
        });

        Schema::create('test_authors', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('country', 2);
        });
    }

    protected function getPackageProviders($app): array
    {
        return [SearchSurgeServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', static::connectionConfig());

        // En los tests queremos ver los filtros nuevos al instante.
        $app['config']->set('search-surge.cache.enabled', false);
    }

    /**
     * La conexión de pruebas, sacada del entorno.
     *
     * Por defecto SQLite en memoria, que es lo que hace la suite rápida. Pero el
     * paquete adapta el SQL a cada motor —ILIKE en PostgreSQL, FIELD() en MySQL,
     * tres sintaxis distintas de EXPLAIN— y eso no se puede verificar sobre un
     * solo driver. Con DB_CONNECTION la misma suite corre contra los tres.
     *
     * @return array<string, mixed>
     */
    protected static function connectionConfig(): array
    {
        return match (static::driver()) {
            'mysql', 'mariadb' => [
                'driver' => 'mysql',
                'host' => env('DB_HOST', '127.0.0.1'),
                'port' => env('DB_PORT', '3306'),
                'database' => env('DB_DATABASE', 'surge_test'),
                'username' => env('DB_USERNAME', 'root'),
                'password' => env('DB_PASSWORD', ''),
                'charset' => 'utf8mb4',
                'collation' => 'utf8mb4_unicode_ci',
                'prefix' => '',
            ],
            'pgsql' => [
                'driver' => 'pgsql',
                'host' => env('DB_HOST', '127.0.0.1'),
                'port' => env('DB_PORT', '5432'),
                'database' => env('DB_DATABASE', 'surge_test'),
                'username' => env('DB_USERNAME', 'postgres'),
                'password' => env('DB_PASSWORD', ''),
                'charset' => 'utf8',
                'prefix' => '',
            ],
            default => [
                'driver' => 'sqlite',
                'database' => ':memory:',
                'prefix' => '',
            ],
        };
    }

    /**
     * El motor contra el que corre la suite.
     */
    protected static function driver(): string
    {
        $driver = env('DB_CONNECTION', 'sqlite');

        return in_array($driver, ['mysql', 'mariadb', 'pgsql'], true) ? $driver : 'sqlite';
    }

    /**
     * ¿Estamos sobre SQLite? Lo usan las pruebas que afirman sobre la forma
     * exacta del SQL, que cambia de un motor a otro (comillas dobles frente a
     * acentos graves) sin que eso signifique nada.
     */
    protected function onSqlite(): bool
    {
        return static::driver() === 'sqlite';
    }

    protected function skipUnlessSqlite(): void
    {
        if (! $this->onSqlite()) {
            $this->markTestSkipped('Afirma sobre la forma del SQL, que depende del motor.');
        }
    }

    protected function skipOnSqlite(string $motivo): void
    {
        if ($this->onSqlite()) {
            $this->markTestSkipped($motivo);
        }
    }

    /* -----------------------------------------------------------------
     | Afirmaciones sobre SQL, independientes del motor
     | ----------------------------------------------------------------- */

    /**
     * El SQL sin el entrecomillado de identificadores.
     *
     * Cada motor cita a su manera: `"col"` en SQLite y PostgreSQL, `` `col` ``
     * en MySQL. Esa diferencia no significa nada, pero basta para que la misma
     * afirmacion pase en un driver y falle en otro. Quitando las comillas, la
     * prueba dice lo que de verdad quiere decir —que la condicion esta y sobre
     * que columna— y vale para los tres.
     *
     * @param  Builder<Model>|string  $query
     */
    protected function sqlOf(Builder|string $query): string
    {
        $sql = $query instanceof Builder ? $query->toSql() : $query;

        // PostgreSQL castea la columna en algunas condiciones ("name"::text
        // like ?). Es ruido del mismo tipo que el entrecomillado: no cambia lo
        // que la consulta hace, pero basta para que la afirmacion falle solo en
        // ese motor. El cast de array_position se conserva porque ahi si
        // significa algo.
        $sql = preg_replace('/::(text|date|bigint|integer)(?!\[)/', '', $sql) ?? $sql;

        return str_replace(['`', '"'], '', $sql);
    }

    /**
     * El operador de comparacion textual del motor actual.
     *
     * PostgreSQL distingue mayusculas con LIKE, asi que TextSearch usa ILIKE
     * para que la busqueda se comporte igual en los tres motores.
     */
    protected function likeOp(): string
    {
        return static::driver() === 'pgsql' ? 'ilike' : 'like';
    }

    /**
     * @param  Builder<Model>|string  $query
     */
    protected function assertSqlHas(string $needle, Builder|string $query, string $message = ''): void
    {
        $this->assertStringContainsString($needle, $this->sqlOf($query), $message);
    }

    /**
     * @param  Builder<Model>|string  $query
     */
    protected function assertSqlMissing(string $needle, Builder|string $query, string $message = ''): void
    {
        $this->assertStringNotContainsString($needle, $this->sqlOf($query), $message);
    }

    /**
     * Cuantas veces aparece un fragmento en el SQL.
     *
     * @param  Builder<Model>|string  $query
     */
    protected function sqlCount(string $needle, Builder|string $query): int
    {
        return substr_count($this->sqlOf($query), $needle);
    }

    /**
     * El SQL con los bindings ya interpolados, para poder afirmar sobre la
     * forma real de la consulta.
     */
    protected function rawSql(Builder $query): string
    {
        $sql = $query->toSql();

        foreach ($query->getBindings() as $binding) {
            $sql = preg_replace('/\?/', "'".(string) $binding."'", $sql, 1);
        }

        return $sql;
    }
}
