<?php

namespace Desar\SearchSurge\Providers;

use Illuminate\Support\ServiceProvider;
use Desar\SearchSurge\Search\Builder;

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
