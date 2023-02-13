<?php

namespace Innoboxrr\SearchSurge\Providers;

use Illuminate\Support\ServiceProvider;
use Innoboxrr\SearchSurge\Search\Builder;

class SearchSurgeServiceProvider extends ServiceProvider
{

    public function register()
    {
        
        $this->app->bind('search-surge', function($app) {
            return new Builder();
        });

    }

    public function boot()
    {
        
        //

    }

}
