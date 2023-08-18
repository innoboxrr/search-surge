<?php

namespace Tests\Unit;

use Innoboxrr\SearchSurge\Search\Builder;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Pagination\LengthAwarePaginator;
use Mockery;
use Innoboxrr\SearchSurge\Tests\TestCase;
use Innoboxrr\SearchSurge\Tests\Models\TestModel;

class BuilderTest extends TestCase
{


    public function testCanSearchWithoutPagination()
    {

        $builder = new Builder();

        $builder->setBasePath(realpath('./') . '\\');

        $result = $builder->get(TestModel::class, ['paginate' => 0], [
            'filtersPath' => 'tests' . DIRECTORY_SEPARATOR . 'Models' . DIRECTORY_SEPARATOR . 'Filters',
            'filtersNamespace' => 'Innoboxrr\SearchSurge\Tests\Models\Filters'
        ]);

        $this->assertInstanceOf(\Illuminate\Support\Collection::class, $result);
    }

    public function testCanSearchWithPagination()
    {

        $builder = new Builder();
        
        $builder->setBasePath(realpath('./') . '\\');

        $result = $builder->get(TestModel::class, ['paginate' => 10], [
            'filtersPath' => 'tests' . DIRECTORY_SEPARATOR . 'Models' . DIRECTORY_SEPARATOR . 'Filters',
            'filtersNamespace' => 'Innoboxrr\SearchSurge\Tests\Models\Filters'
        ]);

        $this->assertInstanceOf(LengthAwarePaginator::class, $result);
    }

    // Aquí puedes agregar más pruebas para otros métodos y funcionalidades
}
