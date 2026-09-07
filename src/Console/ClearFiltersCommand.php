<?php

namespace Innoboxrr\SearchSurge\Console;

use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;
use Innoboxrr\SearchSurge\Search\Support\FilterRegistry;

/**
 * Borra el manifiesto compilado y vuelve al descubrimiento en caliente.
 */
class ClearFiltersCommand extends Command
{
    protected $signature = 'search-surge:clear';

    protected $description = 'Borra el manifiesto de filtros compilado de SearchSurge';

    public function handle(FilterRegistry $registry, Filesystem $files): int
    {
        $path = $registry->manifestPath();

        if ($files->exists($path)) {
            $files->delete($path);
            $this->components->info('Manifiesto de filtros borrado.');
        } else {
            $this->components->info('No había manifiesto que borrar.');
        }

        $registry->forget();

        return self::SUCCESS;
    }
}
