<?php

namespace Innoboxrr\SearchSurge\Tests;

use Innoboxrr\SearchSurge\Search\Builder;
use Innoboxrr\SearchSurge\Providers\SearchSurgeServiceProvider;

class TestCase extends \Orchestra\Testbench\TestCase
{

    public function setUp(): void
    {
        
        parent::setUp();

        // additional setup
        $this->builder = new Builder();

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