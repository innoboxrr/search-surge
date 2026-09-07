<?php

namespace Innoboxrr\SearchSurge\Tests\Unit;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Innoboxrr\SearchSurge\Facades\SearchSurge;
use Innoboxrr\SearchSurge\Search\Builder;
use Innoboxrr\SearchSurge\Search\Filters\Common\IdFilter;
use Innoboxrr\SearchSurge\Search\Filters\Common\SoftDeletesFilter;
use Innoboxrr\SearchSurge\Search\Filters\Common\TimestampsFilter;
use Innoboxrr\SearchSurge\Search\Support\DataContainer;
use Innoboxrr\SearchSurge\Search\Support\FilterRegistry;
use Innoboxrr\SearchSurge\Tests\Models\SoftModel;
use Innoboxrr\SearchSurge\Tests\Models\TestAuthor;
use Innoboxrr\SearchSurge\Tests\Models\TestModel;
use Innoboxrr\SearchSurge\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * Los filtros comunes son la respuesta a los 400 archivos de boilerplate: un
 * modelo nuevo responde a ?id=, ?ids=, ?created_at_start_date= y ?trashed= sin
 * que crees nada.
 */
class CommonFiltersTest extends TestCase
{
    protected function builder(): Builder
    {
        return $this->app->make(Builder::class);
    }

    protected function registry(): FilterRegistry
    {
        return $this->app->make(FilterRegistry::class);
    }

    protected function seedSoft(): void
    {
        // Un insert masivo exige las mismas columnas en todas las filas.
        SoftModel::query()->insert([
            ['id' => 1, 'name' => 'vivo-1', 'created_at' => '2026-01-10 10:00:00', 'updated_at' => '2026-01-10 10:00:00', 'deleted_at' => null],
            ['id' => 2, 'name' => 'vivo-2', 'created_at' => '2026-02-10 10:00:00', 'updated_at' => '2026-02-10 10:00:00', 'deleted_at' => null],
            ['id' => 3, 'name' => 'borrado', 'created_at' => '2026-03-10 10:00:00', 'updated_at' => '2026-03-10 10:00:00', 'deleted_at' => '2026-03-11 10:00:00'],
        ]);
    }

    /* -----------------------------------------------------------------
     | Un modelo sin ningún archivo de filtros
     | ----------------------------------------------------------------- */

    #[Test]
    public function un_modelo_sin_filtros_propios_recibe_los_comunes(): void
    {
        // TestAuthor no tiene directorio de filtros. Antes de esto, buscar
        // sobre el no filtraba absolutamente nada.
        $filters = $this->registry()->resolve(TestAuthor::class);

        $this->assertContains(IdFilter::class, $filters);
        $this->assertContains(TimestampsFilter::class, $filters);
        $this->assertContains(SoftDeletesFilter::class, $filters);
    }

    #[Test]
    public function y_por_tanto_ya_puede_filtrar_por_id_sin_crear_nada(): void
    {
        TestAuthor::query()->insert([
            ['id' => 1, 'name' => 'ana', 'country' => 'MX'],
            ['id' => 2, 'name' => 'beto', 'country' => 'ES'],
            ['id' => 3, 'name' => 'caro', 'country' => 'AR'],
        ]);

        $uno = $this->builder()->get(TestAuthor::class, ['paginate' => 0, 'id' => 2]);
        $varios = $this->builder()->get(TestAuthor::class, ['paginate' => 0, 'ids' => '1,3']);
        $excluidos = $this->builder()->get(TestAuthor::class, ['paginate' => 0, 'id_not' => '1']);

        $this->assertSame(['beto'], $uno->pluck('name')->all());
        $this->assertEqualsCanonicalizing(['ana', 'caro'], $varios->pluck('name')->all());
        $this->assertEqualsCanonicalizing(['beto', 'caro'], $excluidos->pluck('name')->all());
    }

    /* -----------------------------------------------------------------
     | Colisión con los filtros propios
     | ----------------------------------------------------------------- */

    #[Test]
    public function un_modelo_con_su_propio_IdFilter_no_recibe_el_comun(): void
    {
        // Mismo nombre corto de clase: el del modelo manda.
        $this->assertNotContains(IdFilter::class, $this->registry()->resolve(TestModel::class));
    }

    #[Test]
    public function no_se_duplica_el_filtrado_de_fechas(): void
    {
        // TestModel tiene CreationFilter y UpdatedFilter propios. Se llaman
        // distinto que TimestampsFilter, asi que sin la regla de solape de
        // claves las condiciones de fecha se aplicarian dos veces.
        $this->assertNotContains(TimestampsFilter::class, $this->registry()->resolve(TestModel::class));
    }

    #[Test]
    public function una_sola_condicion_por_fecha_en_el_sql(): void
    {
        $sql = $this->builder()->query(TestModel::class, [
            'created_at_start_date' => '2026-01-01',
            'created_at_end_date' => '2026-01-31',
        ])->toSql();

        $this->assertSame(1, $this->sqlCount('test_models.created_at >= ?', $sql));
        $this->assertSame(1, $this->sqlCount('test_models.created_at < ?', $sql));
    }

    #[Test]
    public function los_comunes_que_no_colisionan_si_se_anaden(): void
    {
        // TestModel no declara nada sobre 'trashed', asi que este si entra.
        $this->assertContains(SoftDeletesFilter::class, $this->registry()->resolve(TestModel::class));
    }

    #[Test]
    public function se_pueden_desactivar_del_todo(): void
    {
        config()->set('search-surge.filters.defaults', []);

        $this->registry()->forget();

        $this->assertSame([], $this->registry()->resolve(TestAuthor::class));
    }

    #[Test]
    public function se_pueden_desactivar_para_una_busqueda(): void
    {
        $this->assertSame(
            [],
            $this->registry()->resolve(TestAuthor::class, ['defaults' => []])
        );
    }

    /* -----------------------------------------------------------------
     | IdFilter común
     | ----------------------------------------------------------------- */

    #[Test]
    public function el_id_comun_no_asume_que_la_clave_se_llame_id(): void
    {
        Schema::create('test_codigos', function ($table): void {
            $table->string('codigo')->primary();
            $table->string('nombre');
        });

        $model = new class extends Model
        {
            protected $table = 'test_codigos';

            protected $primaryKey = 'codigo';

            public $incrementing = false;

            protected $keyType = 'string';

            public $timestamps = false;

            protected $guarded = [];
        };

        DB::table('test_codigos')->insert([
            ['codigo' => 'AAA', 'nombre' => 'uno'],
            ['codigo' => 'BBB', 'nombre' => 'dos'],
        ]);

        $names = IdFilter::apply(
            $model->newQuery(),
            new DataContainer(['id' => 'AAA'])
        )->pluck('nombre')->all();

        $this->assertSame(['uno'], $names);
    }

    #[Test]
    public function con_clave_autoincremental_un_id_no_numerico_no_casa_con_nada(): void
    {
        $this->seedSoft();

        $count = $this->builder()->count(SoftModel::class, ['id' => "1' OR '1'='1"]);

        $this->assertSame(0, $count);
    }

    /* -----------------------------------------------------------------
     | TimestampsFilter común
     | ----------------------------------------------------------------- */

    #[Test]
    public function el_filtro_de_timestamps_cubre_las_dos_columnas(): void
    {
        $this->seedSoft();

        $creados = $this->builder()->get(SoftModel::class, [
            'paginate' => 0,
            'created_at_start_date' => '2026-01-01',
            'created_at_end_date' => '2026-01-31',
        ])->pluck('name')->all();

        $this->assertSame(['vivo-1'], $creados);
    }

    #[Test]
    public function se_salta_los_modelos_sin_timestamps(): void
    {
        // TestAuthor tiene $timestamps = false: llamar a getCreatedAtColumn()
        // y filtrar por una columna que no existe reventaria.
        TestAuthor::query()->insert([['id' => 1, 'name' => 'ana', 'country' => 'MX']]);

        $count = $this->builder()->count(TestAuthor::class, [
            'created_at_start_date' => '2020-01-01',
            'created_at_end_date' => '2030-01-01',
        ]);

        $this->assertSame(1, $count);
    }

    #[Test]
    public function el_filtro_de_timestamps_tambien_ordena(): void
    {
        $this->seedSoft();

        $names = $this->builder()->get(SoftModel::class, [
            'paginate' => 0,
            'orderBy' => 'created_at',
            'orderMode' => 'desc',
        ])->pluck('name')->all();

        $this->assertSame(['vivo-2', 'vivo-1'], $names);
    }

    /* -----------------------------------------------------------------
     | SoftDeletesFilter común
     | ----------------------------------------------------------------- */

    #[Test]
    public function por_defecto_no_se_ven_los_borrados(): void
    {
        $this->seedSoft();

        $names = $this->builder()->get(SoftModel::class, ['paginate' => 0])->pluck('name')->all();

        $this->assertEqualsCanonicalizing(['vivo-1', 'vivo-2'], $names);
    }

    #[Test]
    public function trashed_with_incluye_los_borrados(): void
    {
        $this->seedSoft();

        $names = $this->builder()
            ->get(SoftModel::class, ['paginate' => 0, 'trashed' => 'with'])
            ->pluck('name')->all();

        $this->assertCount(3, $names);
    }

    #[Test]
    public function trashed_only_aisla_los_borrados(): void
    {
        $this->seedSoft();

        $names = $this->builder()
            ->get(SoftModel::class, ['paginate' => 0, 'trashed' => 'only'])
            ->pluck('name')->all();

        $this->assertSame(['borrado'], $names);
    }

    #[Test]
    public function un_valor_desconocido_en_trashed_no_expone_borrados(): void
    {
        // La lista de valores es cerrada: cualquier otra cosa no cambia nada.
        $this->seedSoft();

        foreach (['cualquiera', '1', 'true', 'deleted', ''] as $valor) {
            $names = $this->builder()
                ->get(SoftModel::class, ['paginate' => 0, 'trashed' => $valor])
                ->pluck('name')->all();

            $this->assertNotContains('borrado', $names, "trashed={$valor} expuso los borrados");
        }
    }

    #[Test]
    public function no_revienta_con_un_modelo_que_no_usa_soft_deletes(): void
    {
        // withTrashed() sobre un modelo sin el trait lanza BadMethodCall.
        TestModel::query()->insert([['name' => 'ana']]);

        $count = $this->builder()->count(TestModel::class, ['trashed' => 'only']);

        $this->assertSame(1, $count);
    }

    /* -----------------------------------------------------------------
     | Contrato
     | ----------------------------------------------------------------- */

    #[Test]
    public function los_comunes_aparecen_en_el_esquema_de_entrada(): void
    {
        $schema = SearchSurge::schema(TestAuthor::class);

        $this->assertContains('ids', $schema['parameters']);
        $this->assertContains('trashed', $schema['parameters']);
        $this->assertTrue($schema['complete']);
    }
}
