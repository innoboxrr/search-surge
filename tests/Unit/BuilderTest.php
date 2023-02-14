<?php

namespace Innoboxrr\SearchSurge\Tests\Unit;

use Illuminate\Http\Request;
use Innoboxrr\SearchSurge\Tests\TestCase;

class BuilderTest extends TestCase
{

	/** @test */
	public function testGetMethod()
    {
        $request = new Request;

        // Aquí puedes definir la respuesta esperada y el modelo a utilizar
        $expected = null;
        $model = 'Innoboxrr\SearchSurge\Tests\src\Models\TestModel';

        // Llamada al método get
        $result = $this->builder->get($model, $request);

        dd($result);

        // Comparar el resultado con la respuesta esperada
        $this->assertEquals($expected, $result);
    }

}

