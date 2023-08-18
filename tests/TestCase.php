<?php

namespace Innoboxrr\SearchSurge\Tests;

use Innoboxrr\SearchSurge\Search\Builder;
use Innoboxrr\SearchSurge\Providers\SearchSurgeServiceProvider;
use Innoboxrr\SearchSurge\Tests\Models\TestModel;

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;

class TestCase extends \Orchestra\Testbench\TestCase
{

    public function setUp(): void
    {
        
        parent::setUp();

        $this->app->bind(TestModel::class, function ($app) {
            return new TestModel();
        });

        Schema::create('test_models', function (Blueprint $table) {
            $table->id();
            // Define otros campos aquí
        });

    }

    protected function getPackageProviders($app)
    {
        
        return [
            SearchSurgeServiceProvider::class,
        ];

    }

    protected function getEnvironmentSetUp($app)
    {
        
        // perform environment setup

    }

}