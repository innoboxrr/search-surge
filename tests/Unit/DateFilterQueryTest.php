<?php

namespace Innoboxrr\SearchSurge\Tests\Unit;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Innoboxrr\SearchSurge\Search\Support\DataContainer;
use Innoboxrr\SearchSurge\Search\Utils\CreationFilterQuery;
use Innoboxrr\SearchSurge\Search\Utils\UpdatedFilterQuery;
use Innoboxrr\SearchSurge\Tests\Models\TestModel;
use Innoboxrr\SearchSurge\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

class DateFilterQueryTest extends TestCase
{
    protected function newQuery()
    {
        return TestModel::query();
    }

    /* -----------------------------------------------------------------
     | Lo importante: nada de whereDate()
     | ----------------------------------------------------------------- */

    #[Test]
    #[DataProvider('operadores')]
    public function ningun_operador_envuelve_la_columna_en_una_funcion(string $operator): void
    {
        $query = CreationFilterQuery::sort($this->newQuery(), new DataContainer([
            'created_at' => '2026-01-15',
            'operator' => $operator,
        ]));

        $sql = $query->toSql();

        // strftime/date sobre la columna es lo que impedia usar el indice.
        $this->assertStringNotContainsStringIgnoringCase('strftime', $sql);
        $this->assertStringNotContainsStringIgnoringCase('date(', $sql);
        $this->assertSqlHas('test_models.created_at', $sql);
    }

    public static function operadores(): array
    {
        return [
            ['>'], ['>='], ['<'], ['<='], ['=='], ['!='],
        ];
    }

    #[Test]
    public function el_rango_tambien_es_sargable(): void
    {
        $query = CreationFilterQuery::sort($this->newQuery(), new DataContainer([
            'created_at_start_date' => '2026-01-01',
            'created_at_end_date' => '2026-01-31',
        ]));

        $this->assertStringNotContainsStringIgnoringCase('strftime', $query->toSql());
        $this->assertSqlHas('test_models.created_at >= ?', $query->toSql());
        $this->assertSqlHas('test_models.created_at < ?', $query->toSql());
    }

    /* -----------------------------------------------------------------
     | Semántica
     | ----------------------------------------------------------------- */

    #[Test]
    public function la_igualdad_cubre_el_dia_entero(): void
    {
        TestModel::query()->insert([
            ['name' => 'antes', 'created_at' => '2026-01-14 23:59:59', 'updated_at' => now()],
            ['name' => 'temprano', 'created_at' => '2026-01-15 00:00:00', 'updated_at' => now()],
            ['name' => 'tarde', 'created_at' => '2026-01-15 23:59:59', 'updated_at' => now()],
            ['name' => 'despues', 'created_at' => '2026-01-16 00:00:00', 'updated_at' => now()],
        ]);

        $names = CreationFilterQuery::sort($this->newQuery(), new DataContainer([
            'created_at' => '2026-01-15',
            'operator' => '==',
        ]))->pluck('name')->all();

        $this->assertEqualsCanonicalizing(['temprano', 'tarde'], $names);
    }

    #[Test]
    public function mayor_que_significa_a_partir_del_dia_siguiente(): void
    {
        TestModel::query()->insert([
            ['name' => 'mismo_dia', 'created_at' => '2026-01-15 23:00:00', 'updated_at' => now()],
            ['name' => 'siguiente', 'created_at' => '2026-01-16 00:30:00', 'updated_at' => now()],
        ]);

        $names = CreationFilterQuery::sort($this->newQuery(), new DataContainer([
            'created_at' => '2026-01-15',
            'operator' => '>',
        ]))->pluck('name')->all();

        $this->assertSame(['siguiente'], $names);
    }

    #[Test]
    public function el_rango_incluye_el_dia_final_completo(): void
    {
        TestModel::query()->insert([
            ['name' => 'dentro', 'created_at' => '2026-01-31 23:59:59', 'updated_at' => now()],
            ['name' => 'fuera', 'created_at' => '2026-02-01 00:00:00', 'updated_at' => now()],
        ]);

        $names = CreationFilterQuery::sort($this->newQuery(), new DataContainer([
            'created_at_start_date' => '2026-01-01',
            'created_at_end_date' => '2026-01-31',
        ]))->pluck('name')->all();

        $this->assertSame(['dentro'], $names);
    }

    #[Test]
    public function sin_operador_no_filtra_nada(): void
    {
        $sql = CreationFilterQuery::sort($this->newQuery(), new DataContainer([
            'created_at' => '2026-01-15',
        ]))->toSql();

        $this->assertStringNotContainsString('where', strtolower($sql));
    }

    #[Test]
    public function un_operador_desconocido_no_filtra_nada(): void
    {
        $sql = CreationFilterQuery::sort($this->newQuery(), new DataContainer([
            'created_at' => '2026-01-15',
            'operator' => 'DROP TABLE',
        ]))->toSql();

        $this->assertStringNotContainsString('where', strtolower($sql));
    }

    #[Test]
    public function cada_columna_puede_tener_su_propio_operador(): void
    {
        $data = new DataContainer([
            'created_at' => '2026-01-15',
            'created_at_operator' => '>=',
            'updated_at' => '2026-02-15',
            'updated_at_operator' => '<',
            'operator' => '==',
        ]);

        $query = UpdatedFilterQuery::sort(
            CreationFilterQuery::sort($this->newQuery(), $data),
            $data
        );

        $sql = $query->toSql();

        $this->assertSqlHas('test_models.created_at >= ?', $sql);
        $this->assertSqlHas('test_models.updated_at < ?', $sql);
    }

    /* -----------------------------------------------------------------
     | Regresión
     | ----------------------------------------------------------------- */

    #[Test]
    public function el_rango_de_updated_at_filtra_por_updated_at(): void
    {
        // Hasta v2 esta consulta filtraba por created_at por un copy/paste.
        $sql = UpdatedFilterQuery::sort($this->newQuery(), new DataContainer([
            'updated_at_start_date' => '2026-01-01',
            'updated_at_end_date' => '2026-01-31',
        ]))->toSql();

        $this->assertSqlHas('test_models.updated_at', $sql);
        $this->assertStringNotContainsString('created_at', $sql);
    }

    #[Test]
    public function funciona_con_columnas_de_tipo_fecha_sin_hora(): void
    {
        // Los limites se pasan como 'Y-m-d'. Con la forma larga
        // ('2026-01-15 00:00:00'), en SQLite -que compara cadenas- una columna
        // DATE que guarda '2026-01-15' nunca casaria.
        Schema::create('solo_fechas', function ($table): void {
            $table->id();
            $table->string('name');
            $table->date('created_at');
        });

        DB::table('solo_fechas')->insert([
            ['name' => 'dentro', 'created_at' => '2026-01-15'],
            ['name' => 'antes', 'created_at' => '2026-01-14'],
            ['name' => 'despues', 'created_at' => '2026-01-16'],
        ]);

        $model = new class extends Model
        {
            protected $table = 'solo_fechas';

            public $timestamps = false;
        };

        $names = CreationFilterQuery::sort($model->newQuery(), new DataContainer([
            'created_at' => '2026-01-15',
            'operator' => '==',
        ]))->pluck('name')->all();

        $this->assertSame(['dentro'], $names);

        $rango = CreationFilterQuery::sort($model->newQuery(), new DataContainer([
            'created_at_start_date' => '2026-01-14',
            'created_at_end_date' => '2026-01-15',
        ]))->pluck('name')->all();

        $this->assertEqualsCanonicalizing(['antes', 'dentro'], $rango);
    }

    #[Test]
    public function los_limites_se_pasan_como_fecha_sin_hora(): void
    {
        $query = CreationFilterQuery::sort($this->newQuery(), new DataContainer([
            'created_at_start_date' => '2026-01-01',
            'created_at_end_date' => '2026-01-31',
        ]));

        $this->assertSame(['2026-01-01', '2026-02-01'], $query->getBindings());
    }

    #[Test]
    public function una_fecha_ilegible_se_ignora_en_vez_de_reventar(): void
    {
        $sql = CreationFilterQuery::sort($this->newQuery(), new DataContainer([
            'created_at' => 'no-soy-una-fecha',
            'operator' => '>=',
        ]))->toSql();

        $this->assertStringNotContainsString('where', strtolower($sql));
    }
}
