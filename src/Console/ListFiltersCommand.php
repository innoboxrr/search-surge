<?php

namespace Innoboxrr\SearchSurge\Console;

use Illuminate\Console\Command;
use Innoboxrr\SearchSurge\Search\Support\FilterMeta;
use Innoboxrr\SearchSurge\Search\Support\FilterRegistry;
use Innoboxrr\SearchSurge\Search\Support\SearchSchema;

/**
 * Muestra qué filtros se aplicarían a un modelo y en qué orden.
 *
 * Es la herramienta para responder "¿por qué no se está aplicando mi filtro?"
 * sin poner dd() en medio del Builder.
 */
class ListFiltersCommand extends Command
{
    protected $signature = 'search-surge:filters
                            {model? : Clase del modelo (ej. "App\\Models\\User")}
                            {--namespace=* : Namespaces de modelos extra a escanear}
                            {--json : Volcar el contrato de entrada como JSON}';

    protected $description = 'Lista los filtros que SearchSurge resuelve para un modelo';

    public function handle(FilterRegistry $registry, ModelScanner $scanner, SearchSchema $schema): int
    {
        $model = $this->argument('model');

        $models = $model !== null
            ? [ltrim($model, '\\')]
            : $scanner->models($this->option('namespace'));

        if ($this->option('json')) {
            return $this->renderJson($schema, $models);
        }

        if ($models === []) {
            $this->components->warn('No se encontró ningún modelo.');

            return self::SUCCESS;
        }

        $empty = 0;

        foreach ($models as $candidate) {
            if (! class_exists($candidate)) {
                $this->components->error("La clase [{$candidate}] no existe.");

                return self::FAILURE;
            }

            $filters = $registry->resolve($candidate);

            if ($filters === []) {
                $empty++;

                if ($model !== null) {
                    $this->components->warn("[{$candidate}] no tiene filtros resueltos.");
                }

                continue;
            }

            $this->components->twoColumnDetail(
                "<fg=cyan>{$candidate}</>",
                count($filters) . ' filtros'
            );

            foreach ($filters as $filter) {
                $keys = FilterMeta::keys($filter);

                $this->components->twoColumnDetail(
                    '  ' . class_basename($filter),
                    $this->describe($filter, $keys)
                );
            }

            $this->newLine();
        }

        if ($model === null && $empty > 0) {
            $this->components->info("{$empty} modelos sin filtros (omitidos).");
        }

        return self::SUCCESS;
    }

    /**
     * Vuelca el contrato de entrada.
     *
     * Sirve para generar documentación, un esquema OpenAPI o los tipos del
     * front-end sin escribirlos a mano ni que se queden desfasados.
     *
     * @param array<int, class-string> $models
     */
    protected function renderJson(SearchSchema $schema, array $models): int
    {
        $out = [];

        foreach ($models as $model) {
            if (! class_exists($model)) {
                $this->components->error("La clase [{$model}] no existe.");

                return self::FAILURE;
            }

            $out[] = $schema->for($model);
        }

        $this->line((string) json_encode(
            count($out) === 1 ? $out[0] : $out,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        ));

        return self::SUCCESS;
    }

    /**
     * @param array<int, string>|null $keys
     */
    protected function describe(string $filter, ?array $keys): string
    {
        $parts = ['prioridad ' . FilterMeta::priority($filter)];

        if (FilterMeta::isCritical($filter)) {
            $parts[] = '<fg=yellow>autorización</>';
        }

        $parts[] = $keys === null
            ? '<fg=gray>siempre</>'
            : 'si viene: ' . implode(', ', $keys);

        return implode(' · ', $parts);
    }
}
