<?php

namespace Innoboxrr\SearchSurge\Console;

use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;
use Innoboxrr\SearchSurge\Search\Support\FilterRegistry;

/**
 * Compila el mapa modelo => filtros a un archivo PHP.
 *
 * Con el manifiesto en su sitio, un request no toca el disco ni pregunta al
 * autoloader: el mapa se carga de una vez (y desde OPcache) y la resolución de
 * filtros pasa a ser una búsqueda en un array.
 *
 * Ejecútalo en el deploy, junto a config:cache y route:cache.
 */
class CacheFiltersCommand extends Command
{
    protected $signature = 'search-surge:cache
                            {--namespace=* : Namespaces de modelos extra a escanear}
                            {--show : Muestra el mapa resultante}';

    protected $description = 'Compila el mapa de filtros de SearchSurge para evitar el escaneo en tiempo de ejecución';

    public function handle(FilterRegistry $registry, ModelScanner $scanner, Filesystem $files): int
    {
        $path = $registry->manifestPath();

        // El manifiesto viejo no debe influir en la compilación del nuevo.
        if ($files->exists($path)) {
            $files->delete($path);
        }

        $registry->forget();

        $models = $scanner->models($this->option('namespace'));

        if ($models === []) {
            $this->components->warn('No se encontró ningún modelo de Eloquent.');

            return self::SUCCESS;
        }

        $manifest = $registry->compile($models);

        if ($manifest === []) {
            $this->components->warn(
                'Se encontraron ' . count($models) . ' modelos, pero ninguno tiene filtros. Nada que cachear.'
            );

            return self::SUCCESS;
        }

        $files->ensureDirectoryExists(dirname($path));
        $files->put($path, $this->render($manifest));

        $filters = array_sum(array_map('count', $manifest));

        $this->components->info(
            "Manifiesto escrito: {$filters} filtros de " . count($manifest) . ' modelos.'
        );

        if ($this->option('show')) {
            $this->table(
                ['Modelo', 'Filtros'],
                array_map(
                    static fn (string $model, array $list): array => [
                        $model,
                        implode(PHP_EOL, array_map('class_basename', $list)),
                    ],
                    array_keys($manifest),
                    $manifest
                )
            );
        }

        $this->components->twoColumnDetail('Ruta', $path);

        return self::SUCCESS;
    }

    /**
     * @param array<class-string, array<int, class-string>> $manifest
     */
    protected function render(array $manifest): string
    {
        $lines = [
            '<?php',
            '',
            '// Generado por `php artisan search-surge:cache`. No lo edites a mano.',
            '// Vuelve a generarlo cuando añadas o quites filtros.',
            '',
            'return [',
        ];

        foreach ($manifest as $model => $filters) {
            $lines[] = '    ' . var_export($model, true) . ' => [';

            foreach ($filters as $filter) {
                $lines[] = '        ' . var_export($filter, true) . ',';
            }

            $lines[] = '    ],';
        }

        $lines[] = '];';
        $lines[] = '';

        return implode(PHP_EOL, $lines);
    }
}
