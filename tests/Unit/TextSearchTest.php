<?php

namespace Innoboxrr\SearchSurge\Tests\Unit;

use Innoboxrr\SearchSurge\Search\Support\DataContainer;
use Innoboxrr\SearchSurge\Search\Utils\TextSearch;
use Innoboxrr\SearchSurge\Tests\Models\TestModel;
use Innoboxrr\SearchSurge\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

class TextSearchTest extends TestCase
{
    protected function newQuery()
    {
        return TestModel::query();
    }

    protected function seedNames(array $names): void
    {
        TestModel::query()->insert(array_map(
            static fn (string $n): array => ['name' => $n],
            $names
        ));
    }

    /* -----------------------------------------------------------------
     | Forma del SQL
     | ----------------------------------------------------------------- */

    #[Test]
    public function prefix_genera_un_patron_anclado_al_principio(): void
    {
        $query = TextSearch::prefix($this->newQuery(), new DataContainer(['q' => 'zapato']), 'q', ['name']);

        $this->assertSame(['zapato%'], $query->getBindings());
        $this->assertStringContainsString('"test_models"."name" like ?', $query->toSql());
    }

    #[Test]
    public function contains_envuelve_el_termino_por_los_dos_lados(): void
    {
        $query = TextSearch::contains($this->newQuery(), new DataContainer(['q' => 'zapato']), 'q', ['name']);

        $this->assertSame(['%zapato%'], $query->getBindings());
    }

    #[Test]
    public function suffix_ancla_al_final(): void
    {
        $query = TextSearch::suffix($this->newQuery(), new DataContainer(['q' => '.com']), 'q', ['name']);

        $this->assertSame(['%.com'], $query->getBindings());
    }

    #[Test]
    public function varias_columnas_se_agrupan_con_or(): void
    {
        $query = TextSearch::prefix(
            $this->newQuery(),
            new DataContainer(['q' => 'ana']),
            'q',
            ['name', 'owner_id']
        );

        $sql = $query->toSql();

        $this->assertStringContainsString('"test_models"."name" like ?', $sql);
        $this->assertStringContainsString('or "test_models"."owner_id" like ?', $sql);
        $this->assertSame(['ana%', 'ana%'], $query->getBindings());
    }

    #[Test]
    public function el_grupo_va_entre_parentesis_para_no_contaminar_otras_condiciones(): void
    {
        // Sin agrupar, un OR de la busqueda anularia el WHERE anterior.
        $query = TextSearch::contains(
            $this->newQuery()->where('owner_id', 7),
            new DataContainer(['q' => 'ana']),
            'q',
            ['name', 'owner_id']
        );

        $sql = $query->toSql();

        $this->assertMatchesRegularExpression('/"owner_id" = \?.*\(\(.*or.*\)\)/s', $sql);
    }

    #[Test]
    public function cada_palabra_debe_aparecer_en_alguna_columna(): void
    {
        $query = TextSearch::prefix(
            $this->newQuery(),
            new DataContainer(['q' => 'zapato rojo']),
            'q',
            ['name']
        );

        $this->assertSame(['zapato%', 'rojo%'], $query->getBindings());
        // Dos grupos unidos por AND.
        $this->assertSame(2, substr_count($query->toSql(), 'like ?'));
        $this->assertStringNotContainsString('or (', $query->toSql());
    }

    #[Test]
    public function en_modo_any_basta_con_que_aparezca_una_palabra(): void
    {
        $query = TextSearch::prefix(
            $this->newQuery(),
            new DataContainer(['q' => 'zapato rojo']),
            'q',
            ['name'],
            ['mode' => 'any']
        );

        $this->assertStringContainsString('or (', $query->toSql());
    }

    #[Test]
    public function con_split_false_el_termino_va_entero(): void
    {
        $query = TextSearch::prefix(
            $this->newQuery(),
            new DataContainer(['q' => 'zapato rojo']),
            'q',
            ['name'],
            ['split' => false]
        );

        $this->assertSame(['zapato rojo%'], $query->getBindings());
    }

    /* -----------------------------------------------------------------
     | Escapado de comodines
     | ----------------------------------------------------------------- */

    public static function comodines(): array
    {
        // El escape es '~': la barra invertida no es portable entre motores.
        return [
            'porcentaje' => ['%', '~%%'],
            'guion bajo' => ['_', '~_%'],
            'escape literal' => ['~', '~~%'],
            'barra no se toca' => ['\\', '\\%'],
            'mezcla' => ['a%b_c', 'a~%b~_c%'],
        ];
    }

    #[Test]
    #[DataProvider('comodines')]
    public function los_comodines_del_usuario_se_escapan(string $input, string $expected): void
    {
        $query = TextSearch::prefix($this->newQuery(), new DataContainer(['q' => $input]), 'q', ['name']);

        $this->assertSame([$expected], $query->getBindings());
    }

    #[Test]
    public function un_porcentaje_solo_no_devuelve_toda_la_tabla(): void
    {
        // Sin escapar, ?q=% se convierte en "damelo todo": resultados absurdos
        // y una forma barata de tumbar la base.
        $this->seedNames(['ana', 'beto', 'carla']);

        $count = TextSearch::contains(
            $this->newQuery(),
            new DataContainer(['q' => '%']),
            'q',
            ['name']
        )->count();

        $this->assertSame(0, $count);
    }

    #[Test]
    public function un_guion_bajo_no_casa_con_cualquier_caracter(): void
    {
        $this->seedNames(['ana', 'a_a']);

        $names = TextSearch::contains(
            $this->newQuery(),
            new DataContainer(['q' => 'a_a']),
            'q',
            ['name']
        )->pluck('name')->all();

        $this->assertSame(['a_a'], $names);
    }

    #[Test]
    public function el_termino_conserva_el_caracter_de_escape_como_literal(): void
    {
        $this->seedNames(['a~b', 'axb']);

        $names = TextSearch::contains(
            $this->newQuery(),
            new DataContainer(['q' => 'a~b']),
            'q',
            ['name']
        )->pluck('name')->all();

        $this->assertSame(['a~b'], $names);
    }

    #[Test]
    public function emite_la_clausula_escape_para_que_sea_portable(): void
    {
        // Sin ESCAPE explicito, SQLite no interpreta ningun caracter de escape
        // y buscaria los comodines escapados de forma literal.
        $sql = TextSearch::contains(
            $this->newQuery(),
            new DataContainer(['q' => 'ana']),
            'q',
            ['name']
        )->toSql();

        $this->assertStringContainsString("escape '~'", $sql);
    }

    #[Test]
    public function usa_like_en_sqlite_y_no_ilike(): void
    {
        $sql = TextSearch::contains(
            $this->newQuery(),
            new DataContainer(['q' => 'ana']),
            'q',
            ['name']
        )->toSql();

        $this->assertStringContainsString(' like ?', $sql);
        $this->assertStringNotContainsString('ilike', $sql);
    }

    #[Test]
    public function con_caseSensitive_fuerza_like(): void
    {
        $sql = TextSearch::contains(
            $this->newQuery(),
            new DataContainer(['q' => 'ana']),
            'q',
            ['name'],
            ['caseSensitive' => true]
        )->toSql();

        $this->assertStringContainsString(' like ?', $sql);
    }

    /* -----------------------------------------------------------------
     | Guardas
     | ----------------------------------------------------------------- */

    #[Test]
    public function un_termino_vacio_no_filtra(): void
    {
        foreach (['', '   ', null, [], new \stdClass] as $value) {
            $sql = TextSearch::prefix(
                $this->newQuery(),
                new DataContainer(['q' => $value]),
                'q',
                ['name']
            )->toSql();

            $this->assertStringNotContainsString('where', strtolower($sql));
        }
    }

    #[Test]
    public function respeta_la_longitud_minima(): void
    {
        $corto = TextSearch::contains(
            $this->newQuery(),
            new DataContainer(['q' => 'ab']),
            'q',
            ['name'],
            ['minLength' => 3]
        );

        $this->assertStringNotContainsString('where', strtolower($corto->toSql()));

        $largo = TextSearch::contains(
            $this->newQuery(),
            new DataContainer(['q' => 'abc']),
            'q',
            ['name'],
            ['minLength' => 3]
        );

        $this->assertStringContainsString('like ?', $largo->toSql());
    }

    #[Test]
    public function la_longitud_minima_se_puede_fijar_por_configuracion(): void
    {
        config()->set('search-surge.text.min_length', 4);

        $sql = TextSearch::contains(
            $this->newQuery(),
            new DataContainer(['q' => 'abc']),
            'q',
            ['name']
        )->toSql();

        $this->assertStringNotContainsString('where', strtolower($sql));
    }

    #[Test]
    public function acota_el_numero_de_palabras(): void
    {
        // Sin tope, pegar un parrafo genera cientos de grupos anidados.
        $parrafo = implode(' ', array_map(static fn (int $i): string => "palabra{$i}", range(1, 50)));

        $query = TextSearch::prefix(
            $this->newQuery(),
            new DataContainer(['q' => $parrafo]),
            'q',
            ['name'],
            ['maxTerms' => 5]
        );

        $this->assertCount(5, $query->getBindings());
    }

    #[Test]
    public function el_tope_por_defecto_de_palabras_es_ocho(): void
    {
        $parrafo = implode(' ', array_map(static fn (int $i): string => "p{$i}", range(1, 40)));

        $query = TextSearch::prefix(
            $this->newQuery(),
            new DataContainer(['q' => $parrafo]),
            'q',
            ['name']
        );

        $this->assertCount(8, $query->getBindings());
    }

    #[Test]
    public function sin_columnas_no_filtra(): void
    {
        $sql = TextSearch::prefix($this->newQuery(), new DataContainer(['q' => 'ana']), 'q', [])->toSql();

        $this->assertStringNotContainsString('where', strtolower($sql));
    }

    #[Test]
    public function colapsa_los_espacios_repetidos(): void
    {
        $query = TextSearch::prefix(
            $this->newQuery(),
            new DataContainer(['q' => "  zapato    rojo  \n "]),
            'q',
            ['name']
        );

        $this->assertSame(['zapato%', 'rojo%'], $query->getBindings());
    }

    /* -----------------------------------------------------------------
     | Resultados reales
     | ----------------------------------------------------------------- */

    #[Test]
    public function prefix_solo_encuentra_lo_que_empieza_por_el_termino(): void
    {
        $this->seedNames(['zapato rojo', 'mi zapato', 'zapatilla']);

        $names = TextSearch::prefix(
            $this->newQuery(),
            new DataContainer(['q' => 'zapato']),
            'q',
            ['name']
        )->pluck('name')->all();

        $this->assertEqualsCanonicalizing(['zapato rojo'], $names);
    }

    #[Test]
    public function contains_encuentra_el_termino_en_cualquier_posicion(): void
    {
        $this->seedNames(['zapato rojo', 'mi zapato', 'zapatilla']);

        $names = TextSearch::contains(
            $this->newQuery(),
            new DataContainer(['q' => 'zapato']),
            'q',
            ['name']
        )->pluck('name')->all();

        $this->assertEqualsCanonicalizing(['zapato rojo', 'mi zapato'], $names);
    }

    #[Test]
    public function varias_palabras_se_exigen_todas(): void
    {
        $this->seedNames(['zapato rojo grande', 'zapato azul', 'camisa roja']);

        $names = TextSearch::contains(
            $this->newQuery(),
            new DataContainer(['q' => 'zapato rojo']),
            'q',
            ['name']
        )->pluck('name')->all();

        $this->assertSame(['zapato rojo grande'], $names);
    }

    /* -----------------------------------------------------------------
     | Texto completo
     | ----------------------------------------------------------------- */

    #[Test]
    public function sqlite_no_soporta_texto_completo(): void
    {
        $this->assertFalse(TextSearch::supportsFullText($this->newQuery()));
    }

    #[Test]
    public function fullText_degrada_a_contains_cuando_el_driver_no_lo_soporta(): void
    {
        $query = TextSearch::fullText(
            $this->newQuery(),
            new DataContainer(['q' => 'zapato']),
            'q',
            ['name']
        );

        $this->assertSame(['%zapato%'], $query->getBindings());
    }

    #[Test]
    public function fullText_puede_degradar_a_prefix(): void
    {
        $query = TextSearch::fullText(
            $this->newQuery(),
            new DataContainer(['q' => 'zapato']),
            'q',
            ['name'],
            ['fallback' => 'prefix']
        );

        $this->assertSame(['zapato%'], $query->getBindings());
    }

    #[Test]
    public function fullText_puede_lanzar_en_vez_de_degradar(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('no soporta busqueda de texto completo');

        TextSearch::fullText(
            $this->newQuery(),
            new DataContainer(['q' => 'zapato']),
            'q',
            ['name'],
            ['fallback' => 'throw']
        );
    }

    #[Test]
    public function fullText_sin_termino_no_filtra_ni_degrada(): void
    {
        $sql = TextSearch::fullText(
            $this->newQuery(),
            new DataContainer(['q' => '']),
            'q',
            ['name']
        )->toSql();

        $this->assertStringNotContainsString('where', strtolower($sql));
    }
}
