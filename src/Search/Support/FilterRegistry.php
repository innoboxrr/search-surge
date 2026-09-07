<?php

namespace Innoboxrr\SearchSurge\Search\Support;

use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Foundation\Application;

/**
 * Descubre y cachea qué clases de filtro le corresponden a cada modelo.
 *
 * El objetivo de diseño es que en producción no se toque el disco ni una vez:
 * el registro en memoria y el manifiesto compilado cubren ese caso, y el
 * escaneo de directorios queda solo como red de seguridad para desarrollo.
 */
class FilterRegistry
{
    /**
     * Filtros declarados explícitamente. modelo FQCN => lista de filtros.
     *
     * @var array<class-string, array<int, class-string>>
     */
    protected array $map = [];

    /**
     * Namespace de modelos => namespace de filtros.
     *
     * @var array<string, string>
     */
    protected array $namespaces = [];

    /**
     * Resultados ya resueltos en este proceso. Evita repetir el trabajo cuando
     * un mismo request busca varias veces sobre el mismo modelo.
     *
     * @var array<string, array<int, class-string>>
     */
    protected array $memo = [];

    /**
     * Manifiesto compilado por `search-surge:cache`, si existe.
     *
     * @var array<class-string, array<int, class-string>>|null
     */
    protected ?array $manifest = null;

    public function __construct(
        protected Application $app,
        protected ConfigRepository $config,
        protected CacheFactory $cache,
    ) {
    }

    /* -----------------------------------------------------------------
     | Registro público
     | ----------------------------------------------------------------- */

    /**
     * Declara explícitamente los filtros de un modelo. Es la vía más rápida:
     * cero disco, cero convención, cero sorpresas.
     *
     * @param class-string $model
     * @param array<int, class-string> $filters
     */
    public function register(string $model, array $filters): static
    {
        $model = ltrim($model, '\\');

        $this->map[$model] = array_values(array_unique(
            array_merge($this->map[$model] ?? [], array_map(
                static fn (string $filter): string => ltrim($filter, '\\'),
                $filters
            ))
        ));

        $this->forgetMemo($model);

        return $this;
    }

    /**
     * Registra varios modelos de una vez.
     *
     * @param array<class-string, array<int, class-string>> $map
     */
    public function registerMany(array $map): static
    {
        foreach ($map as $model => $filters) {
            $this->register($model, $filters);
        }

        return $this;
    }

    /**
     * Le dice a SearchSurge dónde viven los filtros de todo un namespace de
     * modelos. Es la línea que un paquete pone en su ServiceProvider cuando no
     * sigue la convención por defecto.
     */
    public function registerNamespace(string $modelNamespace, string $filtersNamespace): static
    {
        $this->namespaces[trim($modelNamespace, '\\')] = trim($filtersNamespace, '\\');

        $this->memo = [];

        return $this;
    }

    /**
     * @return array<string, string>
     */
    public function namespaces(): array
    {
        return array_merge(
            (array) $this->config->get('search-surge.filters.namespaces', []),
            $this->namespaces,
        );
    }

    /**
     * @return array<class-string, array<int, class-string>>
     */
    public function registered(): array
    {
        return array_merge(
            (array) $this->config->get('search-surge.filters.map', []),
            $this->map,
        );
    }

    /**
     * Olvida lo memoizado (y la caché persistente) de un modelo, o de todos.
     *
     * @param class-string|null $model
     */
    public function forget(?string $model = null): static
    {
        if ($model === null) {
            $this->memo = [];
            $this->manifest = null;

            return $this;
        }

        $this->forgetMemo(ltrim($model, '\\'));

        return $this;
    }

    /**
     * Olvida todo lo memoizado de un modelo, incluidas las entradas resueltas
     * con otras opciones: la clave lleva las opciones dentro, así que borrar
     * solo la clave desnuda dejaría variantes obsoletas vivas.
     */
    protected function forgetMemo(string $model): void
    {
        foreach (array_keys($this->memo) as $key) {
            if ($key === $model || str_starts_with($key, $model . '|')) {
                unset($this->memo[$key]);
            }
        }
    }

    /* -----------------------------------------------------------------
     | Resolución
     | ----------------------------------------------------------------- */

    /**
     * Los filtros aplicables a un modelo, ya ordenados y verificados.
     *
     * @param class-string $model
     * @param array<string, mixed> $options
     * @return array<int, class-string>
     */
    public function resolve(string $model, array $options = []): array
    {
        $model = ltrim($model, '\\');
        $key = $this->memoKey($model, $options);

        if (isset($this->memo[$key])) {
            return $this->memo[$key];
        }

        return $this->memo[$key] = $this->sort($this->discover($model, $options));
    }

    /**
     * @param class-string $model
     * @param array<string, mixed> $options
     * @return array<int, class-string>
     */
    protected function discover(string $model, array $options): array
    {
        // 1. Lista explícita en las opciones de la llamada.
        if (! empty($options['filters']) && is_array($options['filters'])) {
            return $this->qualify($options['filters'], $options['filtersNamespace'] ?? null, $model);
        }

        // 2. Registro en memoria / config map.
        $registered = $this->registered();

        if (! empty($registered[$model])) {
            return $this->qualify($registered[$model], null, $model);
        }

        // 3. Manifiesto compilado (search-surge:cache).
        $manifest = $this->manifest();

        if (! empty($manifest[$model])) {
            return $this->qualify($manifest[$model], null, $model);
        }

        // 4. Declarado por el propio modelo.
        if (method_exists($model, 'surgeFilters')) {
            $declared = $model::surgeFilters();

            if (! empty($declared)) {
                return $this->qualify($declared, null, $model);
            }
        }

        // 5..7. Escaneo por convención, con caché.
        return $this->scan($model, $options);
    }

    /**
     * Escanea el primer directorio candidato que exista.
     *
     * @param class-string $model
     * @param array<string, mixed> $options
     * @return array<int, class-string>
     */
    protected function scan(string $model, array $options): array
    {
        $candidates = $this->candidates($model, $options);

        if ($candidates === []) {
            return [];
        }

        $cacheKey = $this->config->get('search-surge.cache.prefix', 'search-surge:filters:')
            . sha1($model . '|' . json_encode(array_map(
                static fn (array $c): array => [$c['namespace'], $c['directory']],
                $candidates
            )));

        $resolver = function () use ($candidates): array {
            foreach ($candidates as $candidate) {
                $found = $this->classesIn($candidate['directory'], $candidate['namespace']);

                if ($found !== []) {
                    return $found;
                }
            }

            return [];
        };

        if (! $this->cacheEnabled()) {
            return $resolver();
        }

        return $this->store()->remember(
            $cacheKey,
            (int) $this->config->get('search-surge.cache.ttl', 86400),
            $resolver
        );
    }

    /**
     * Directorios donde podrían vivir los filtros de este modelo, del candidato
     * más específico al más genérico.
     *
     * @param class-string $model
     * @param array<string, mixed> $options
     * @return array<int, array{namespace: string, directory: string}>
     */
    protected function candidates(string $model, array $options): array
    {
        $shortName = class_basename($model);
        $modelNamespace = $this->namespaceOf($model);
        $candidates = [];

        $push = function (?string $namespace, ?string $directory) use (&$candidates): void {
            if ($namespace === null || $namespace === '' || $directory === null) {
                return;
            }

            $namespace = trim($namespace, '\\');
            $directory = rtrim($directory, DIRECTORY_SEPARATOR);

            foreach ($candidates as $existing) {
                if ($existing['namespace'] === $namespace && $existing['directory'] === $directory) {
                    return;
                }
            }

            $candidates[] = ['namespace' => $namespace, 'directory' => $directory];
        };

        // Ruta física explícita (modo legado, sigue teniendo prioridad).
        if (! empty($options['filtersPath'])) {
            $namespace = trim((string) ($options['filtersNamespace']
                ?? $this->config->get('search-surge.filters.namespace', 'App\\Models\\Filters')), '\\')
                . '\\' . $shortName;

            $base = $options['basePath'] ?? (function_exists('base_path') ? base_path() . DIRECTORY_SEPARATOR : '');

            $push($namespace, $base . trim((string) $options['filtersPath'], DIRECTORY_SEPARATOR)
                . DIRECTORY_SEPARATOR . $shortName);
        }

        // Namespace explícito en las opciones: la ruta la deduce Composer.
        if (! empty($options['filtersNamespace'])) {
            $namespace = trim((string) $options['filtersNamespace'], '\\') . '\\' . $shortName;
            $push($namespace, ComposerLocator::directoryFor($namespace));
        }

        // Namespaces registrados por paquetes / config, del prefijo más largo al más corto.
        $namespaces = $this->namespaces();
        uksort($namespaces, static fn (string $a, string $b): int => strlen($b) <=> strlen($a));

        foreach ($namespaces as $modelPrefix => $filtersPrefix) {
            if ($modelNamespace === $modelPrefix || str_starts_with($modelNamespace . '\\', $modelPrefix . '\\')) {
                $namespace = $filtersPrefix . '\\' . $shortName;
                $push($namespace, ComposerLocator::directoryFor($namespace));
            }
        }

        // Convención: App\Models\User -> App\Models\Filters\User
        $suffix = trim((string) $this->config->get('search-surge.filters.suffix', 'Filters'), '\\');

        if ($modelNamespace !== '' && $suffix !== '') {
            $namespace = $modelNamespace . '\\' . $suffix . '\\' . $shortName;
            $push($namespace, ComposerLocator::directoryFor($namespace));
        }

        // Último recurso: lo configurado globalmente.
        $fallbackNamespace = trim((string) $this->config->get('search-surge.filters.namespace', ''), '\\');

        if ($fallbackNamespace !== '') {
            $namespace = $fallbackNamespace . '\\' . $shortName;
            $push($namespace, ComposerLocator::directoryFor($namespace));

            $fallbackPath = (string) $this->config->get('search-surge.filters.path', '');

            if ($fallbackPath !== '' && function_exists('base_path')) {
                $push($namespace, base_path(trim($fallbackPath, DIRECTORY_SEPARATOR)
                    . DIRECTORY_SEPARATOR . $shortName));
            }
        }

        return $candidates;
    }

    /**
     * Las clases de filtro que hay en un directorio.
     *
     * @return array<int, class-string>
     */
    protected function classesIn(string $directory, string $namespace): array
    {
        if (! is_dir($directory)) {
            return [];
        }

        $files = glob($directory . DIRECTORY_SEPARATOR . '*.php') ?: [];

        // glob no garantiza orden en todos los sistemas de archivos.
        sort($files, SORT_STRING);

        $classes = [];

        foreach ($files as $file) {
            $class = $namespace . '\\' . basename($file, '.php');

            if ($this->isFilter($class)) {
                $classes[] = $class;
            }
        }

        return $classes;
    }

    /**
     * Normaliza nombres cortos a FQCN y descarta lo que no sea un filtro real.
     *
     * @param array<int, string> $filters
     * @param class-string|null $model
     * @return array<int, class-string>
     */
    protected function qualify(array $filters, ?string $namespace, ?string $model = null): array
    {
        $shortName = $model !== null ? class_basename($model) : null;
        $qualified = [];

        foreach ($filters as $filter) {
            $filter = ltrim((string) $filter, '\\');

            if (! str_contains($filter, '\\') && $namespace !== null && $shortName !== null) {
                $filter = trim($namespace, '\\') . '\\' . $shortName . '\\' . $filter;
            }

            if ($this->isFilter($filter)) {
                $qualified[] = $filter;
            }
        }

        return array_values(array_unique($qualified));
    }

    /**
     * @phpstan-assert-if-true class-string $class
     */
    protected function isFilter(string $class): bool
    {
        return class_exists($class) && method_exists($class, 'apply');
    }

    /**
     * Ordena por la propiedad estática $priority (menor primero) y, a igualdad,
     * de forma estable por nombre para que el SQL generado sea determinista.
     *
     * @param array<int, class-string> $filters
     * @return array<int, class-string>
     */
    protected function sort(array $filters): array
    {
        if (count($filters) < 2) {
            return $filters;
        }

        $decorated = [];

        foreach ($filters as $index => $filter) {
            $decorated[] = [FilterMeta::priority($filter), $index, $filter];
        }

        usort($decorated, static fn (array $a, array $b): int => [$a[0], $a[1]] <=> [$b[0], $b[1]]);

        return array_column($decorated, 2);
    }

    /* -----------------------------------------------------------------
     | Manifiesto y caché
     | ----------------------------------------------------------------- */

    /**
     * Recorre todo lo conocido y devuelve el mapa modelo => filtros, para que
     * el comando `search-surge:cache` lo escriba a disco.
     *
     * @param array<int, class-string> $models
     * @return array<class-string, array<int, class-string>>
     */
    public function compile(array $models): array
    {
        $manifest = [];

        foreach ($models as $model) {
            $model = ltrim($model, '\\');
            $filters = $this->sort($this->discover($model, []));

            if ($filters !== []) {
                $manifest[$model] = $filters;
            }
        }

        return $manifest;
    }

    /**
     * @return array<class-string, array<int, class-string>>
     */
    public function manifest(): array
    {
        if ($this->manifest !== null) {
            return $this->manifest;
        }

        $path = $this->manifestPath();

        if (! is_file($path)) {
            return $this->manifest = [];
        }

        $manifest = require $path;

        return $this->manifest = is_array($manifest) ? $manifest : [];
    }

    public function manifestPath(): string
    {
        if ($this->app->bound('path.bootstrap')) {
            return $this->app->bootstrapPath('cache' . DIRECTORY_SEPARATOR . 'search-surge.php');
        }

        return $this->app->storagePath('framework' . DIRECTORY_SEPARATOR . 'search-surge.php');
    }

    protected function cacheEnabled(): bool
    {
        $enabled = $this->config->get('search-surge.cache.enabled');

        // null = automático: cachear solo en producción, para que en local un
        // filtro nuevo se vea al instante sin limpiar nada.
        if ($enabled === null) {
            return method_exists($this->app, 'isProduction') && $this->app->isProduction();
        }

        return (bool) $enabled;
    }

    protected function store(): \Illuminate\Contracts\Cache\Repository
    {
        return $this->cache->store($this->config->get('search-surge.cache.store'));
    }

    /**
     * @param array<string, mixed> $options
     */
    protected function memoKey(string $model, array $options): string
    {
        $relevant = array_filter([
            $options['filtersPath'] ?? null,
            $options['filtersNamespace'] ?? null,
            $options['basePath'] ?? null,
            isset($options['filters']) ? implode(',', (array) $options['filters']) : null,
        ]);

        return $relevant === [] ? $model : $model . '|' . implode('|', $relevant);
    }

    protected function namespaceOf(string $class): string
    {
        $position = strrpos($class, '\\');

        return $position === false ? '' : substr($class, 0, $position);
    }
}
