<?php

namespace Innoboxrr\SearchSurge\Tests\Unit;

use Illuminate\Support\Facades\Gate;
use Innoboxrr\SearchSurge\Search\Utils\CreationFilterQuery;
use Innoboxrr\SearchSurge\Search\Utils\Managed;
use Innoboxrr\SearchSurge\Search\Utils\NumericFilterQuery;
use Innoboxrr\SearchSurge\Search\Utils\Order;
use Innoboxrr\SearchSurge\Search\Utils\RelationFilterQuery;
use Innoboxrr\SearchSurge\Search\Utils\SetFilterQuery;
use Innoboxrr\SearchSurge\Search\Utils\UpdatedFilterQuery;
use Innoboxrr\SearchSurge\Tests\Models\SoftModel;
use Innoboxrr\SearchSurge\Tests\Models\TestAuthor;
use Innoboxrr\SearchSurge\Tests\Models\TestModel;
use Innoboxrr\SearchSurge\Tests\Models\TestUser;
use Innoboxrr\SearchSurge\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * Las utilidades aceptan un DataContainer o cualquier objeto con las
 * propiedades, para no romper los filtros escritos contra v2, cuando lo que
 * llegaba era el Request.
 *
 * Esa promesa estaba en la documentacion y en seis clases, y no la verificaba
 * ni una prueba: todas pasaban un DataContainer. Una promesa de compatibilidad
 * sin test es una promesa que se rompe en el primer refactor.
 */
class LegacyDataTest extends TestCase
{
    /**
     * Un objeto cualquiera con propiedades publicas, como lo era el Request.
     */
    protected function datos(array $valores): object
    {
        return (object) $valores;
    }

    /* -----------------------------------------------------------------
     | Fechas
     | ----------------------------------------------------------------- */

    #[Test]
    public function las_fechas_aceptan_un_objeto_suelto(): void
    {
        TestModel::query()->insert([
            ['name' => 'enero', 'created_at' => '2026-01-15 10:00:00', 'updated_at' => '2026-01-15 10:00:00'],
            ['name' => 'marzo', 'created_at' => '2026-03-15 10:00:00', 'updated_at' => '2026-03-15 10:00:00'],
        ]);

        $names = CreationFilterQuery::sort(TestModel::query(), $this->datos([
            'created_at_start_date' => '2026-01-01',
            'created_at_end_date' => '2026-01-31',
        ]))->pluck('name')->all();

        $this->assertSame(['enero'], $names);
    }

    #[Test]
    public function el_operador_de_fecha_tambien_se_lee_de_un_objeto(): void
    {
        $sql = CreationFilterQuery::sort(TestModel::query(), $this->datos([
            'created_at' => '2026-01-15',
            'operator' => '>=',
        ]));

        $this->assertSqlHas('test_models.created_at >= ?', $sql);
    }

    #[Test]
    public function una_fecha_ilegible_en_un_objeto_se_ignora(): void
    {
        $sql = UpdatedFilterQuery::sort(TestModel::query(), $this->datos([
            'updated_at' => 'no-soy-fecha',
            'operator' => '>=',
        ]))->toSql();

        $this->assertStringNotContainsString('where', strtolower($sql));
    }

    #[Test]
    public function una_fecha_vacia_en_un_objeto_se_ignora(): void
    {
        foreach ([null, '', []] as $valor) {
            $sql = CreationFilterQuery::sort(TestModel::query(), $this->datos([
                'created_at_start_date' => $valor,
            ]))->toSql();

            $this->assertStringNotContainsString('where', strtolower($sql));
        }
    }

    /* -----------------------------------------------------------------
     | Orden
     | ----------------------------------------------------------------- */

    #[Test]
    public function el_orden_acepta_un_objeto_suelto(): void
    {
        $sql = Order::orderBy(TestModel::query(), $this->datos([
            'orderBy' => 'name',
            'orderMode' => 'desc',
        ]), 'name');

        $this->assertSqlHas('order by name desc', $sql);
    }

    #[Test]
    public function el_orden_por_defecto_acepta_un_objeto_suelto(): void
    {
        $sql = Order::fallback(TestModel::query(), $this->datos([]), 'id', 'desc');

        $this->assertSqlHas('order by id desc', $sql);
    }

    /* -----------------------------------------------------------------
     | Números
     | ----------------------------------------------------------------- */

    #[Test]
    public function los_numeros_aceptan_un_objeto_suelto(): void
    {
        TestModel::query()->insert([
            ['name' => 'barato', 'price' => 10],
            ['name' => 'caro', 'price' => 100],
        ]);

        $names = NumericFilterQuery::apply(TestModel::query(), $this->datos([
            'price_max' => 50,
        ]), 'price')->pluck('name')->all();

        $this->assertSame(['barato'], $names);
    }

    #[Test]
    public function el_operador_numerico_tambien_se_lee_de_un_objeto(): void
    {
        $sql = NumericFilterQuery::apply(TestModel::query(), $this->datos([
            'price' => 50,
            'price_operator' => '>',
        ]), 'price');

        $this->assertSqlHas('test_models.price > ?', $sql);
    }

    /* -----------------------------------------------------------------
     | Conjuntos
     | ----------------------------------------------------------------- */

    #[Test]
    public function los_conjuntos_aceptan_un_objeto_suelto(): void
    {
        TestModel::query()->insert([
            ['name' => 'a', 'status' => 'borrador'],
            ['name' => 'b', 'status' => 'publicado'],
            ['name' => 'c', 'status' => 'archivado'],
        ]);

        $lista = SetFilterQuery::apply(TestModel::query(), $this->datos([
            'status' => 'borrador,publicado',
        ]), 'status')->pluck('name')->all();

        $suelto = SetFilterQuery::apply(TestModel::query(), $this->datos([
            'status' => 'archivado',
        ]), 'status')->pluck('name')->all();

        $array = SetFilterQuery::apply(TestModel::query(), $this->datos([
            'status' => ['borrador'],
        ]), 'status')->pluck('name')->all();

        $this->assertEqualsCanonicalizing(['a', 'b'], $lista);
        $this->assertSame(['c'], $suelto);
        $this->assertSame(['a'], $array);
    }

    #[Test]
    public function un_conjunto_vacio_en_un_objeto_no_filtra(): void
    {
        foreach ([null, ''] as $valor) {
            $sql = SetFilterQuery::apply(TestModel::query(), $this->datos([
                'status' => $valor,
            ]), 'status')->toSql();

            $this->assertStringNotContainsString('where', strtolower($sql));
        }
    }

    #[Test]
    public function la_exclusion_por_conjunto_acepta_un_objeto(): void
    {
        TestModel::query()->insert([
            ['name' => 'a', 'status' => 'borrador'],
            ['name' => 'b', 'status' => 'publicado'],
        ]);

        $names = SetFilterQuery::apply(TestModel::query(), $this->datos([
            'status_not' => 'borrador',
        ]), 'status')->pluck('name')->all();

        $this->assertSame(['b'], $names);
    }

    #[Test]
    public function excluir_null_deja_solo_los_que_tienen_valor(): void
    {
        TestModel::query()->insert([
            ['name' => 'con', 'status' => 'borrador'],
            ['name' => 'sin', 'status' => null],
        ]);

        $names = SetFilterQuery::apply(TestModel::query(), $this->datos([
            'status_not' => 'null',
        ]), 'status')->pluck('name')->all();

        $this->assertSame(['con'], $names);
    }

    /* -----------------------------------------------------------------
     | Relaciones
     | ----------------------------------------------------------------- */

    #[Test]
    public function las_relaciones_aceptan_un_objeto_suelto(): void
    {
        TestAuthor::query()->insert([
            ['id' => 1, 'name' => 'ana', 'country' => 'MX'],
            ['id' => 2, 'name' => 'beto', 'country' => 'ES'],
        ]);

        SoftModel::query()->insert([
            ['name' => 'post-mx', 'author_id' => 1, 'deleted_at' => null],
            ['name' => 'post-es', 'author_id' => 2, 'deleted_at' => null],
            ['name' => 'huerfano', 'author_id' => null, 'deleted_at' => null],
        ]);

        $porColumna = RelationFilterQuery::column(
            SoftModel::query(),
            $this->datos(['author_country' => 'MX']),
            'author',
            'country'
        )->pluck('name')->all();

        $porExistencia = RelationFilterQuery::exists(
            SoftModel::query(),
            $this->datos(['has_author' => 0]),
            'author'
        )->pluck('name')->all();

        $porConteo = RelationFilterQuery::count(
            TestAuthor::query(),
            $this->datos(['posts_count_min' => 1]),
            'posts'
        )->pluck('name')->all();

        $this->assertSame(['post-mx'], $porColumna);
        $this->assertSame(['huerfano'], $porExistencia);
        $this->assertEqualsCanonicalizing(['ana', 'beto'], $porConteo);
    }

    #[Test]
    public function una_relacion_con_lista_en_un_objeto(): void
    {
        TestAuthor::query()->insert([
            ['id' => 1, 'name' => 'ana', 'country' => 'MX'],
            ['id' => 2, 'name' => 'beto', 'country' => 'ES'],
        ]);

        SoftModel::query()->insert([
            ['name' => 'post-mx', 'author_id' => 1, 'deleted_at' => null],
            ['name' => 'post-es', 'author_id' => 2, 'deleted_at' => null],
        ]);

        $names = RelationFilterQuery::column(
            SoftModel::query(),
            $this->datos(['author_country' => 'MX,ES']),
            'author',
            'country'
        )->pluck('name')->all();

        $this->assertCount(2, $names);

        $vacio = RelationFilterQuery::column(
            SoftModel::query(),
            $this->datos(['author_country' => null]),
            'author',
            'country'
        )->toSql();

        $this->assertStringNotContainsString('exists', strtolower($vacio));
    }

    /* -----------------------------------------------------------------
     | Autorización
     | ----------------------------------------------------------------- */

    #[Test]
    public function la_autorizacion_acepta_un_objeto_suelto(): void
    {
        $user = TestUser::query()->create(['id' => 3, 'name' => 'ana']);
        $this->be($user);

        $filtro = new class extends Managed
        {
            public static function canView($query, $user, array $args = [])
            {
                return $query->where('owner_id', $user->getAuthIdentifier());
            }
        };

        $query = $filtro::apply(TestModel::query(), $this->datos([
            'managed' => true,
            'managedFilterClass' => $filtro::class,
            'modelClassName' => TestModel::class,
        ]));

        $this->assertSqlHas('owner_id = ?', $query);
    }

    #[Test]
    public function la_cadena_false_en_un_objeto_tampoco_activa_la_restriccion(): void
    {
        $this->be(TestUser::query()->create(['id' => 3, 'name' => 'ana']));

        $query = Managed::apply(TestModel::query(), $this->datos(['managed' => 'false']));

        $this->assertSqlMissing('owner_id', $query);
    }

    #[Test]
    public function sin_filtro_de_permisos_valido_la_consulta_no_se_toca(): void
    {
        $this->be(TestUser::query()->create(['id' => 3, 'name' => 'ana']));

        foreach (['NoExiste', null, 42] as $filtro) {
            $query = Managed::apply(TestModel::query(), $this->datos([
                'managed' => true,
                'managedFilterClass' => $filtro,
            ]));

            $this->assertSqlMissing('owner_id', $query);
        }
    }

    #[Test]
    public function el_modelo_se_deduce_de_la_instancia_si_no_viene_el_nombre(): void
    {
        $this->be(TestUser::query()->create(['id' => 3, 'name' => 'ana']));

        Gate::define('viewAny', fn ($user, $model = null): bool => true);

        // Sin modelClassName, Managed cae en la instancia de modelClass.
        $query = Managed::apply(TestModel::query(), $this->datos([
            'managed' => true,
            'except_view_any' => true,
            'modelClass' => new TestModel,
        ]));

        $this->assertSqlMissing('owner_id', $query);
    }
}
