<?php

namespace Desar\SearchSurge\Facades;

use Illuminate\Support\Facades\Facade;

class SearchSurge extends Facade
{
    
    protected static function getFacadeAccessor()
    {
        return 'search-surge';
    }

}