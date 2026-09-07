<?php

namespace Innoboxrr\SearchSurge\Tests\Unit;

use Innoboxrr\SearchSurge\Search\Support\DataContainer;
use Innoboxrr\SearchSurge\Search\Utils\NumericFilterQuery;
use Innoboxrr\SearchSurge\Search\Utils\RelationFilterQuery;
use Innoboxrr\SearchSurge\Search\Utils\SetFilterQuery;
use Innoboxrr\SearchSurge\Tests\Models\SoftModel;
use Innoboxrr\SearchSurge\Tests\Models\TestAuthor;
use Innoboxrr\SearchSurge\Tests\Models\TestModel;
use Innoboxrr\SearchSurge\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

class QueryUtilsTest extends TestCase
{
    protected function newQuery()
    {
        return TestModel::query();
    }

    protected function seedPrices(): void
    {
        TestModel::query()->insert([
            ['name' => 'a', 'price' => 10],
            ['name' => 'b', 'price' => 50],
            ['name' => 'c', 'price' => 100],
            ['name' => 'd', 'price' => null],
        ]);
    }

    /* -----------------------------------------------------------------
     | NumericFilterQuery
     | ----------------------------------------------------------------- */

    #[Test]
    public function filtra_por_rango_numerico(): void
    {
        $this->seedPrices();

        $names = NumericFilterQuery::apply($this->newQuery(), new DataContainer([
            'price_min' => 20,
            'price_max' => 100,
        ]), 'price')->pluck('name')->all();

        $this->assertEqualsCanonicalizing(['b', 'c'], $names);
    }

    #[Test]
    public function los_limites_del_rango_son_inclusivos(): void
    {
        $this->seedPrices();

        $names = NumericFilterQuery::apply($this->newQuery(), new DataContainer([
            'price_min' => 10,
            'price_max' => 50,
        ]), 'price')->pluck('name')->all();

        $this->assertEqualsCanonicalizing(['a', 'b'], $names);
    }

    #[Test]
    #[DataProvider('operadoresNumericos')]
    public function aplica_cada_operador(string $operator, array $esperado): void
    {
        $this->seedPrices();

        $names = NumericFilterQuery::apply($this->newQuery(), new DataContainer([
            'price' => 50,
            'operator' => $operator,
        ]), 'price')->pluck('name')->all();

        $this->assertEqualsCanonicalizing($esperado, $names);
    }

    public static function operadoresNumericos(): array
    {
        return [
            ['>', ['c']],
            ['>=', ['b', 'c']],
            ['<', ['a']],
            ['<=', ['a', 'b']],
            ['==', ['b']],
            ['!=', ['a', 'c']],
        ];
    }

    #[Test]
    public function un_valor_no_numerico_no_se_convierte_en_cero(): void
    {
        // Confundir "no filtres por precio" con "dame los de precio 0" es un
        // error que no se ve: la consulta funciona y devuelve poco.
        $sql = NumericFilterQuery::apply($this->newQuery(), new DataContainer([
            'price' => 'gratis',
            'operator' => '<=',
        ]), 'price')->toSql();

        $this->assertStringNotContainsString('where', strtolower($sql));
    }

    #[Test]
    public function sin_operador_un_valor_suelto_no_filtra(): void
    {
        $sql = NumericFilterQuery::apply($this->newQuery(), new DataContainer([
            'price' => 50,
        ]), 'price')->toSql();

        $this->assertStringNotContainsString('where', strtolower($sql));
    }

    #[Test]
    public function cada_columna_puede_llevar_su_propio_operador(): void
    {
        $sql = NumericFilterQuery::apply($this->newQuery(), new DataContainer([
            'price' => 50,
            'price_operator' => '>',
            'operator' => '==',
        ]), 'price')->toSql();

        $this->assertStringContainsString('"test_models"."price" > ?', $sql);
    }

    #[Test]
    public function los_valores_numericos_viajan_como_bindings(): void
    {
        $query = NumericFilterQuery::apply($this->newQuery(), new DataContainer([
            'price_min' => '10.5',
        ]), 'price');

        $this->assertSame([10.5], $query->getBindings());
    }

    #[Test]
    public function un_operador_desconocido_no_filtra(): void
    {
        $sql = NumericFilterQuery::apply($this->newQuery(), new DataContainer([
            'price' => 50,
            'operator' => 'DROP TABLE',
        ]), 'price')->toSql();

        $this->assertStringNotContainsString('where', strtolower($sql));
    }

    #[Test]
    public function expone_las_claves_que_consume(): void
    {
        $this->assertSame(
            ['price', 'price_operator', 'price_min', 'price_max', 'operator'],
            NumericFilterQuery::keys('price')
        );
    }

    /* -----------------------------------------------------------------
     | SetFilterQuery
     | ----------------------------------------------------------------- */

    protected function seedStatuses(): void
    {
        TestModel::query()->insert([
            ['name' => 'a', 'status' => 'borrador'],
            ['name' => 'b', 'status' => 'publicado'],
            ['name' => 'c', 'status' => 'archivado'],
            ['name' => 'd', 'status' => null],
        ]);
    }

    #[Test]
    public function filtra_por_una_lista_de_valores(): void
    {
        $this->seedStatuses();

        $names = SetFilterQuery::apply($this->newQuery(), new DataContainer([
            'status' => 'borrador,publicado',
        ]), 'status')->pluck('name')->all();

        $this->assertEqualsCanonicalizing(['a', 'b'], $names);
    }

    #[Test]
    public function acepta_un_array_o_un_valor_suelto(): void
    {
        $this->seedStatuses();

        $array = SetFilterQuery::apply($this->newQuery(), new DataContainer([
            'status' => ['borrador'],
        ]), 'status')->pluck('name')->all();

        $suelto = SetFilterQuery::apply($this->newQuery(), new DataContainer([
            'status' => 'borrador',
        ]), 'status')->pluck('name')->all();

        $this->assertSame(['a'], $array);
        $this->assertSame(['a'], $suelto);
    }

    #[Test]
    public function excluye_con_el_sufijo_not(): void
    {
        $this->seedStatuses();

        $names = SetFilterQuery::apply($this->newQuery(), new DataContainer([
            'status_not' => 'borrador,archivado',
        ]), 'status')->pluck('name')->all();

        $this->assertSame(['b'], $names);
    }

    #[Test]
    public function respeta_la_lista_blanca(): void
    {
        $this->seedStatuses();

        $names = SetFilterQuery::apply(
            $this->newQuery(),
            new DataContainer(['status' => 'borrador,archivado']),
            'status',
            ['borrador', 'publicado']
        )->pluck('name')->all();

        $this->assertSame(['a'], $names);
    }

    #[Test]
    public function si_nada_sobrevive_a_la_lista_blanca_devuelve_cero(): void
    {
        // Pediste ?status=inventado y no hay nada asi. Ignorar la condicion
        // devolveria la tabla entera, que es lo contrario de lo que pediste.
        $this->seedStatuses();

        $count = SetFilterQuery::apply(
            $this->newQuery(),
            new DataContainer(['status' => 'inventado']),
            'status',
            ['borrador', 'publicado']
        )->count();

        $this->assertSame(0, $count);
    }

    #[Test]
    public function el_literal_null_busca_los_vacios(): void
    {
        $this->seedStatuses();

        $names = SetFilterQuery::apply($this->newQuery(), new DataContainer([
            'status' => 'null',
        ]), 'status')->pluck('name')->all();

        $this->assertSame(['d'], $names);
    }

    #[Test]
    public function puede_combinar_null_con_valores_concretos(): void
    {
        $this->seedStatuses();

        $names = SetFilterQuery::apply($this->newQuery(), new DataContainer([
            'status' => 'borrador,null',
        ]), 'status')->pluck('name')->all();

        $this->assertEqualsCanonicalizing(['a', 'd'], $names);
    }

    #[Test]
    public function sin_valores_no_filtra(): void
    {
        foreach (['', null, []] as $valor) {
            $sql = SetFilterQuery::apply($this->newQuery(), new DataContainer([
                'status' => $valor,
            ]), 'status')->toSql();

            $this->assertStringNotContainsString('where', strtolower($sql));
        }
    }

    #[Test]
    public function los_valores_del_conjunto_viajan_como_bindings(): void
    {
        $query = SetFilterQuery::apply($this->newQuery(), new DataContainer([
            'status' => "a','b'); DROP TABLE test_models;--",
        ]), 'status');

        $this->assertStringNotContainsString('DROP', strtoupper($query->toSql()));
    }

    /* -----------------------------------------------------------------
     | RelationFilterQuery
     | ----------------------------------------------------------------- */

    protected function seedAuthors(): void
    {
        TestAuthor::query()->insert([
            ['id' => 1, 'name' => 'ana', 'country' => 'MX'],
            ['id' => 2, 'name' => 'beto', 'country' => 'ES'],
        ]);

        SoftModel::query()->insert([
            ['name' => 'post-1', 'author_id' => 1],
            ['name' => 'post-2', 'author_id' => 1],
            ['name' => 'post-3', 'author_id' => 2],
            ['name' => 'huerfano', 'author_id' => null],
        ]);
    }

    #[Test]
    public function filtra_por_existencia_de_la_relacion(): void
    {
        $this->seedAuthors();

        $con = RelationFilterQuery::exists(SoftModel::query(), new DataContainer([
            'has_author' => 1,
        ]), 'author')->pluck('name')->all();

        $sin = RelationFilterQuery::exists(SoftModel::query(), new DataContainer([
            'has_author' => 0,
        ]), 'author')->pluck('name')->all();

        $this->assertEqualsCanonicalizing(['post-1', 'post-2', 'post-3'], $con);
        $this->assertSame(['huerfano'], $sin);
    }

    #[Test]
    public function sin_la_clave_de_existencia_no_filtra(): void
    {
        $this->seedAuthors();

        $count = RelationFilterQuery::exists(SoftModel::query(), new DataContainer([]), 'author')->count();

        $this->assertSame(4, $count);
    }

    #[Test]
    public function filtra_por_una_columna_del_otro_lado(): void
    {
        $this->seedAuthors();

        $names = RelationFilterQuery::column(
            SoftModel::query(),
            new DataContainer(['author_country' => 'MX']),
            'author',
            'country'
        )->pluck('name')->all();

        $this->assertEqualsCanonicalizing(['post-1', 'post-2'], $names);
    }

    #[Test]
    public function la_columna_de_la_relacion_acepta_listas(): void
    {
        $this->seedAuthors();

        $names = RelationFilterQuery::column(
            SoftModel::query(),
            new DataContainer(['author_country' => 'MX,ES']),
            'author',
            'country'
        )->pluck('name')->all();

        $this->assertCount(3, $names);
    }

    #[Test]
    public function la_columna_de_la_relacion_respeta_la_lista_blanca(): void
    {
        $this->seedAuthors();

        $sql = RelationFilterQuery::column(
            SoftModel::query(),
            new DataContainer(['author_country' => 'XX']),
            'author',
            'country',
            null,
            ['MX', 'ES']
        )->toSql();

        $this->assertStringNotContainsString('exists', strtolower($sql));
    }

    #[Test]
    public function filtra_por_cuantos_relacionados_hay(): void
    {
        $this->seedAuthors();

        $names = RelationFilterQuery::count(
            TestAuthor::query(),
            new DataContainer(['posts_count_min' => 2]),
            'posts'
        )->pluck('name')->all();

        $this->assertSame(['ana'], $names);
    }

    #[Test]
    public function expone_las_claves_de_la_relacion(): void
    {
        $this->assertSame(
            ['has_author', 'author_count_min', 'author_count_max', 'author_country'],
            RelationFilterQuery::keys('author', ['country'])
        );
    }

    #[Test]
    public function usa_whereHas_y_no_intenta_adivinar_una_estrategia(): void
    {
        // MySQL 8 unifica whereHas, whereIn con subconsulta y JOIN en el mismo
        // plan: un "selector inteligente" seria complejidad a cambio de nada.
        $sql = RelationFilterQuery::column(
            SoftModel::query(),
            new DataContainer(['author_country' => 'MX']),
            'author',
            'country'
        )->toSql();

        $this->assertStringContainsString('exists (select', strtolower($sql));
    }
}
