<?php

namespace Innoboxrr\SearchSurge\Tests\Unit;

use Illuminate\Contracts\Pagination\Paginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Innoboxrr\SearchSurge\Facades\SearchSurge;
use Innoboxrr\SearchSurge\Search\Builder;
use Innoboxrr\SearchSurge\Search\Support\ComposerLocator;
use Innoboxrr\SearchSurge\Search\Support\FilterMeta;
use Innoboxrr\SearchSurge\Search\Support\FilterRegistry;
use Innoboxrr\SearchSurge\Tests\Fixtures\Filters\BrokenFilter;
use Innoboxrr\SearchSurge\Tests\Fixtures\Filters\KeyedNameFilter;
use Innoboxrr\SearchSurge\Tests\Models\TestModel;
use Innoboxrr\SearchSurge\Tests\Models\TestUser;
use Innoboxrr\SearchSurge\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

/**
 * Qué pasa cuando algo va mal: filtros mal escritos, manifiestos corruptos,
 * datos con formas raras. Nada de esto debería tumbar una búsqueda.
 */
class RobustnessTest extends TestCase
{
    protected function builder(): Builder
    {
        return $this->app->make(Builder::class);
    }

    /* -----------------------------------------------------------------
     | Filtros que se portan mal
     | ----------------------------------------------------------------- */

    public static function retornosRaros(): array
    {
        return [
            'null' => [null],
            'string' => ['soy un string'],
            'array' => [[1, 2, 3]],
            'entero' => [42],
            'false' => [false],
        ];
    }

    #[Test]
    #[DataProvider('retornosRaros')]
    public function un_filtro_que_devuelve_cualquier_cosa_no_rompe_la_busqueda(mixed $retorno): void
    {
        $filter = new class($retorno)
        {
            public static mixed $retorno = null;

            public function __construct(mixed $r)
            {
                self::$retorno = $r;
            }

            public static function apply($query, $data)
            {
                $query->where('name', 'algo');

                return self::$retorno;
            }
        };

        $result = $this->builder()->get(TestModel::class, ['paginate' => 0], [
            'filters' => [$filter::class],
        ]);

        // El builder ignora el retorno invalido y conserva la consulta mutada.
        $this->assertInstanceOf(Collection::class, $result);
    }

    #[Test]
    public function un_filtro_sin_metodo_apply_se_descarta_en_silencio(): void
    {
        $filters = $this->app->make(FilterRegistry::class)
            ->resolve(TestModel::class, ['filters' => [\stdClass::class, KeyedNameFilter::class]]);

        $this->assertSame([KeyedNameFilter::class], $filters);
    }

    #[Test]
    public function una_clase_de_filtro_inexistente_no_ensucia_el_log(): void
    {
        // En v2 se registraba un error en cada peticion por cada filtro que no
        // existiera. Ahora simplemente no se resuelve.
        Log::shouldReceive('error')->never();

        $filters = $this->app->make(FilterRegistry::class)
            ->resolve(TestModel::class, ['filters' => ['App\\Filters\\NoExiste']]);

        $this->assertSame([], $filters);
    }

    #[Test]
    public function un_filtro_roto_no_impide_que_los_demas_se_apliquen(): void
    {
        Log::shouldReceive('error')->once();

        $sql = $this->builder()->query(TestModel::class, ['name' => 'ana'], [
            'filters' => [BrokenFilter::class, KeyedNameFilter::class],
        ])->toSql();

        $this->assertStringContainsString('"name" like ?', $sql);
    }

    #[Test]
    public function un_filtro_que_agota_la_memoria_o_lanza_un_Error_tambien_se_captura(): void
    {
        $filter = new class
        {
            public static function apply($query, $data)
            {
                // TypeError: un \Error, no una \Exception.
                return strlen([]); // @phpstan-ignore-line
            }
        };

        Log::shouldReceive('error')->once();

        $result = $this->builder()->get(TestModel::class, ['paginate' => 0], [
            'filters' => [$filter::class],
        ]);

        $this->assertInstanceOf(Collection::class, $result);
    }

    /* -----------------------------------------------------------------
     | Metadatos raros en los filtros
     | ----------------------------------------------------------------- */

    #[Test]
    public function unas_keys_que_no_son_un_array_se_ignoran(): void
    {
        $filter = new class
        {
            public static $keys = 'no soy un array';

            public static int $calls = 0;

            public static function apply($query, $data)
            {
                self::$calls++;

                return $query;
            }
        };

        $this->builder()->get(TestModel::class, ['paginate' => 0], ['filters' => [$filter::class]]);

        // Sin $keys valido, el filtro se ejecuta siempre.
        $this->assertSame(1, $filter::$calls);
    }

    #[Test]
    public function unas_keys_vacias_no_apagan_el_filtro(): void
    {
        $filter = new class
        {
            public static array $keys = [];

            public static int $calls = 0;

            public static function apply($query, $data)
            {
                self::$calls++;

                return $query;
            }
        };

        $this->builder()->get(TestModel::class, ['paginate' => 0], ['filters' => [$filter::class]]);

        $this->assertSame(1, $filter::$calls);
    }

    #[Test]
    public function una_priority_no_numerica_se_trata_como_cero(): void
    {
        $filter = new class
        {
            public static $priority = 'alta';

            public static function apply($query, $data)
            {
                return $query;
            }
        };

        $this->assertSame(0, FilterMeta::priority($filter::class));
    }

    #[Test]
    public function una_keys_privada_o_no_estatica_no_se_lee(): void
    {
        $filter = new class
        {
            protected static array $keys = ['jamas'];

            public static int $calls = 0;

            public static function apply($query, $data)
            {
                self::$calls++;

                return $query;
            }
        };

        $this->builder()->get(TestModel::class, ['paginate' => 0], ['filters' => [$filter::class]]);

        $this->assertSame(1, $filter::$calls);
    }

    /* -----------------------------------------------------------------
     | Manifiesto corrupto
     | ----------------------------------------------------------------- */

    #[Test]
    public function un_manifiesto_que_no_devuelve_un_array_se_ignora(): void
    {
        $registry = $this->app->make(FilterRegistry::class);
        $path = $registry->manifestPath();

        File::ensureDirectoryExists(dirname($path));
        File::put($path, "<?php\n\nreturn 'esto no es un array';\n");

        $registry->forget();

        $this->assertSame([], $registry->manifest());
        $this->assertNotEmpty($registry->resolve(TestModel::class));

        File::delete($path);
    }

    #[Test]
    public function un_manifiesto_con_clases_inexistentes_cae_a_la_convencion(): void
    {
        $registry = $this->app->make(FilterRegistry::class);
        $path = $registry->manifestPath();

        File::ensureDirectoryExists(dirname($path));
        File::put($path, "<?php\n\nreturn [".var_export(TestModel::class, true)." => ['App\\\\NoExiste']];\n");

        $registry->forget();

        // Las clases del manifiesto se verifican; si ninguna existe, no se
        // devuelve una lista con basura. Solo quedan los filtros comunes.
        foreach ($registry->resolve(TestModel::class) as $filter) {
            $this->assertStringStartsWith('Innoboxrr\\SearchSurge\\Search\\Filters\\Common\\', $filter);
        }

        File::delete($path);
    }

    #[Test]
    public function sin_manifiesto_todo_sigue_funcionando(): void
    {
        $registry = $this->app->make(FilterRegistry::class);

        if (File::exists($registry->manifestPath())) {
            File::delete($registry->manifestPath());
        }

        $registry->forget();

        $this->assertNotEmpty($registry->resolve(TestModel::class));
    }

    /* -----------------------------------------------------------------
     | Localizador de namespaces
     | ----------------------------------------------------------------- */

    #[Test]
    public function el_localizador_devuelve_vacio_para_lo_que_no_conoce(): void
    {
        $this->assertSame([], ComposerLocator::directoriesFor('Vendor\\Fantasma\\Models'));
        $this->assertNull(ComposerLocator::directoryFor('Vendor\\Fantasma\\Models'));
        $this->assertSame([], ComposerLocator::directoriesFor(''));
        $this->assertSame([], ComposerLocator::directoriesFor('\\\\'));
    }

    #[Test]
    public function el_localizador_resuelve_el_namespace_de_los_tests(): void
    {
        $directory = ComposerLocator::directoryFor('Innoboxrr\\SearchSurge\\Tests\\Models\\Filters\\TestModel');

        $this->assertNotNull($directory);
        $this->assertDirectoryExists($directory);
        $this->assertFileExists($directory.DIRECTORY_SEPARATOR.'IdFilter.php');
    }

    #[Test]
    public function el_localizador_prefiere_el_prefijo_mas_especifico(): void
    {
        // 'Innoboxrr\SearchSurge\Tests\' es mas especifico que 'Innoboxrr\SearchSurge\'.
        $tests = ComposerLocator::directoryFor('Innoboxrr\\SearchSurge\\Tests\\Models');
        $src = ComposerLocator::directoryFor('Innoboxrr\\SearchSurge\\Search\\Utils');

        $this->assertStringContainsString('tests', str_replace('\\', '/', strtolower((string) $tests)));
        $this->assertStringContainsString('src', str_replace('\\', '/', strtolower((string) $src)));
    }

    /* -----------------------------------------------------------------
     | Datos con formas inesperadas
     | ----------------------------------------------------------------- */

    public static function datosRaros(): array
    {
        return [
            'array anidado' => [['id' => ['a' => ['b' => 'c']]]],
            'objeto' => [['id' => new \stdClass]],
            'booleano' => [['id' => true]],
            'float' => [['id' => 1.5]],
            'string largo' => [['name' => str_repeat('x', 100000)]],
            'unicode' => [['name' => "ñ日本語🚀\u{202E}"]],
            'clave numerica' => [[0 => 'a', 1 => 'b']],
            'null en todo' => [['id' => null, 'name' => null, 'paginate' => null]],
        ];
    }

    #[Test]
    #[DataProvider('datosRaros')]
    public function datos_con_formas_raras_no_tumban_la_busqueda(array $data): void
    {
        // Se acepta cualquiera de las dos formas de resultado: lo que se prueba
        // es que la busqueda termina, no que pagine de una manera concreta.
        $result = $this->builder()->get(TestModel::class, $data);

        $this->assertTrue(
            $result instanceof Collection
            || $result instanceof Paginator,
            'La busqueda no devolvio un resultado valido.'
        );

        $sinPaginar = $this->builder()->get(TestModel::class, array_merge($data, ['paginate' => 0]));

        $this->assertInstanceOf(Collection::class, $sinPaginar);
    }

    #[Test]
    public function un_modelo_sin_ningun_filtro_devuelve_la_consulta_limpia(): void
    {
        $result = $this->builder()->get(
            TestUser::class,
            ['paginate' => 0]
        );

        $this->assertInstanceOf(Collection::class, $result);
    }

    #[Test]
    public function dos_busquedas_anidadas_no_se_pisan(): void
    {
        $outer = $this->builder();
        $inner = $this->builder();

        $outerQuery = $outer->query(TestModel::class, ['name' => 'ana'], [
            'filters' => [KeyedNameFilter::class],
        ]);

        $inner->query(TestModel::class, ['id' => 5]);

        // La consulta externa no debe haberse alterado por la interna.
        $this->assertStringContainsString('"name" like ?', $outerQuery->toSql());
        $this->assertStringNotContainsString('"id" = ?', $outerQuery->toSql());
    }

    #[Test]
    public function el_facade_entrega_una_instancia_nueva_en_cada_llamada(): void
    {
        $a = SearchSurge::getFacadeRoot();
        $b = SearchSurge::getFacadeRoot();

        $this->assertNotSame($a, $b);
    }
}
