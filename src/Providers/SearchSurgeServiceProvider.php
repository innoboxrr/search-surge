<?php

namespace Innoboxrr\SearchSurge\Providers;

use Illuminate\Support\ServiceProvider;
use Innoboxrr\SearchSurge\Console\CacheFiltersCommand;
use Innoboxrr\SearchSurge\Console\ClearFiltersCommand;
use Innoboxrr\SearchSurge\Console\ExplainCommand;
use Innoboxrr\SearchSurge\Console\ListFiltersCommand;
use Innoboxrr\SearchSurge\Console\MakeFilterCommand;
use Innoboxrr\SearchSurge\Search\Builder;
use Innoboxrr\SearchSurge\Search\Support\FilterRegistry;

class SearchSurgeServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(
            __DIR__ . '/../../config/search-surge.php',
            'search-surge'
        );

        // El registro es singleton: guarda lo que declaran los paquetes y
        // memoiza el descubrimiento durante todo el ciclo de vida del proceso.
        $this->app->singleton(FilterRegistry::class, fn ($app) => new FilterRegistry(
            $app,
            $app->make('config'),
            $app->make('cache'),
        ));

        // El Builder, en cambio, es transitorio: mantiene el estado de una
        // búsqueda concreta.
        $this->app->bind(Builder::class, fn ($app) => new Builder(
            $app->make(FilterRegistry::class),
            $app,
            $app->make('config'),
        ));

        $this->app->bind('search-surge', fn ($app) => $app->make(Builder::class));
    }

    public function boot(): void
    {
        if (! $this->app->runningInConsole()) {
            return;
        }

        $this->publishes([
            __DIR__ . '/../../config/search-surge.php' => $this->app->configPath('search-surge.php'),
        ], ['search-surge', 'search-surge-config']);

        $this->commands([
            CacheFiltersCommand::class,
            ClearFiltersCommand::class,
            ExplainCommand::class,
            ListFiltersCommand::class,
            MakeFilterCommand::class,
        ]);
    }
}
