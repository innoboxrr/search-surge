<?php

namespace Innoboxrr\SearchSurge\Tests\Unit;

use Innoboxrr\SearchSurge\Search\Support\DataContainer;
use Innoboxrr\SearchSurge\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

class DataContainerTest extends TestCase
{
    #[Test]
    public function el_acceso_por_propiedad_sigue_funcionando(): void
    {
        $data = new DataContainer(['id' => 7]);

        $this->assertSame(7, $data->id);
        $this->assertNull($data->desconocido);
    }

    #[Test]
    public function isset_ya_no_miente(): void
    {
        // Sin __isset, isset($data->id) era siempre false y empty() se equivocaba.
        $data = new DataContainer(['id' => 7, 'nulo' => null]);

        $this->assertTrue(isset($data->id));
        $this->assertFalse(isset($data->nulo));
        $this->assertFalse(empty($data->id));
    }

    #[Test]
    public function boolean_no_se_traga_la_cadena_false(): void
    {
        // Este era el bug: 'false' == true es verdadero en PHP.
        $data = new DataContainer([
            'a' => 'false',
            'b' => 'true',
            'c' => '0',
            'd' => '1',
            'e' => 'on',
            'f' => 0,
        ]);

        $this->assertFalse($data->boolean('a'));
        $this->assertTrue($data->boolean('b'));
        $this->assertFalse($data->boolean('c'));
        $this->assertTrue($data->boolean('d'));
        $this->assertTrue($data->boolean('e'));
        $this->assertFalse($data->boolean('f'));
        $this->assertFalse($data->boolean('inexistente'));
    }

    #[Test]
    public function filled_distingue_vacio_de_ausente(): void
    {
        $data = new DataContainer(['a' => '', 'b' => '  ', 'c' => [], 'd' => null, 'e' => '0']);

        $this->assertFalse($data->filled('a'));
        $this->assertFalse($data->filled('b'));
        $this->assertFalse($data->filled('c'));
        $this->assertFalse($data->filled('d'));
        $this->assertTrue($data->filled('e'));

        $this->assertTrue($data->has('d'));
        $this->assertFalse($data->has('inexistente'));
    }

    #[Test]
    public function array_parte_las_listas_separadas_por_comas(): void
    {
        $data = new DataContainer(['ids' => '1,2,3', 'uno' => 5, 'lista' => [7, 8], 'vacio' => '']);

        $this->assertSame(['1', '2', '3'], $data->array('ids'));
        $this->assertSame([5], $data->array('uno'));
        $this->assertSame([7, 8], $data->array('lista'));
        $this->assertSame([], $data->array('vacio'));
    }

    #[Test]
    public function date_devuelve_null_en_vez_de_reventar(): void
    {
        $data = new DataContainer(['ok' => '2026-01-15', 'mala' => 'xyz', 'vacia' => '']);

        $this->assertSame('2026-01-15', $data->date('ok')->toDateString());
        $this->assertNull($data->date('mala'));
        $this->assertNull($data->date('vacia'));
    }

    #[Test]
    public function los_casteos_numericos_son_tolerantes(): void
    {
        $data = new DataContainer(['n' => '42', 'f' => '3.5', 'malo' => 'abc']);

        $this->assertSame(42, $data->integer('n'));
        $this->assertSame(3.5, $data->float('f'));
        $this->assertSame(0, $data->integer('malo'));
        $this->assertSame(9, $data->integer('malo', 9));
    }

    #[Test]
    public function se_comporta_como_array_y_como_iterable(): void
    {
        $data = new DataContainer(['a' => 1, 'b' => 2]);

        $this->assertSame(1, $data['a']);
        $this->assertTrue(isset($data['b']));
        $this->assertCount(2, $data);
        $this->assertSame(['a' => 1, 'b' => 2], iterator_to_array($data));
        $this->assertSame(['a' => 1, 'b' => 2], $data->toArray());
    }

    #[Test]
    public function soporta_notacion_de_puntos(): void
    {
        $data = new DataContainer(['filtros' => ['estado' => 'activo']]);

        $this->assertSame('activo', $data->get('filtros.estado'));
        $this->assertTrue($data->has('filtros.estado'));
        $this->assertNull($data->get('filtros.inexistente'));
    }

    #[Test]
    public function anyFilled_solo_cuenta_claves_con_contenido(): void
    {
        $data = new DataContainer(['a' => '', 'b' => 'x']);

        $this->assertFalse($data->anyFilled(['a']));
        $this->assertTrue($data->anyFilled(['a', 'b']));
        $this->assertTrue($data->hasAny(['a']));
    }

    #[Test]
    public function los_setters_encadenan(): void
    {
        $data = (new DataContainer([]))
            ->set('a', 1)
            ->merge(['b' => 2])
            ->fillMissing('a', 99)
            ->fillMissing('c', 3);

        $this->assertSame(['a' => 1, 'b' => 2, 'c' => 3], $data->all());

        $data->forget('b');

        $this->assertFalse($data->has('b'));
    }
}
