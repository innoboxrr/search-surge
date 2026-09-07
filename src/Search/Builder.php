<?php

namespace Innoboxrr\SearchSurge\Search;

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Pagination\CursorPaginator;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\LazyCollection;
use Innoboxrr\SearchSurge\Events\SearchExecuted;
use Innoboxrr\SearchSurge\Exceptions\PageLimitExceededException;
use Innoboxrr\SearchSurge\Search\Support\DataContainer;
use Innoboxrr\SearchSurge\Search\Support\FilterMeta;
use Innoboxrr\SearchSurge\Search\Support\FilterRegistry;
use InvalidArgumentException;
use Throwable;

/**
 * Punto de entrada de SearchSurge.
 *
 * El estado se reinicia en cada llamada a query()/get(), así que una misma
 * instancia se puede reutilizar sin que las opciones de una búsqueda se filtren
 * a la siguiente.
 */
class Builder
{
    /* -----------------------------------------------------------------
     | Dependencias
     | ----------------------------------------------------------------- */

    protected Container $container;

    protected FilterRegistry $registry;

    protected ConfigRepository $config;

    /* -----------------------------------------------------------------
     | Estado de la búsqueda en curso
     | ----------------------------------------------------------------- */

    /** Ruta base para el modo legado de $options['filtersPath']. */
    protected ?string $basePath = null;

    /** Instancia del modelo. Se mantiene el nombre por compatibilidad. */
    protected ?Model $modelClass = null;

    /** @var class-string<Model>|null */
    protected ?string $modelClassName = null;

    protected ?string $modelName = null;

    /** @var EloquentBuilder<Model>|null */
    protected $modelQuery = null;

    /** @var array<int, class-string> */
    protected array $filters = [];

    /** Los que de verdad se ejecutaron, para el evento. @var array<int, class-string> */
    protected array $appliedFilters = [];

    /** Opciones de la búsqueda en curso. @var array<string, mixed> */
    protected array $options = [];

    /**
     * Opciones fijadas con setOptions() fuera de get(). v2 permitía
     * preconfigurar el builder y luego llamar a get() sin opciones, así que
     * estas persisten entre llamadas; las que se pasan a get() no.
     *
     * @var array<string, mixed>
     */
    protected array $stickyOptions = [];

    protected ?DataContainer $data = null;

    protected const DEFAULT_FILTERS_PATH = 'app'.DIRECTORY_SEPARATOR.'Models'.DIRECTORY_SEPARATOR.'Filters';

    /** Legado: se sigue exponiendo dentro de $data. */
    protected string $filtersPath = self::DEFAULT_FILTERS_PATH;

    protected ?string $filtersRealPath = null;

    protected const DEFAULT_FILTERS_NAMESPACE = 'App\\Models\\Filters';

    protected string $filtersNamespace = self::DEFAULT_FILTERS_NAMESPACE;

    public function __construct(
        ?FilterRegistry $registry = null,
        ?Container $container = null,
        ?ConfigRepository $config = null,
    ) {
        $this->container = $container ?? \Illuminate\Container\Container::getInstance();
        $this->registry = $registry ?? $this->container->make(FilterRegistry::class);
        $this->config = $config ?? $this->container->make('config');
    }

    /* -----------------------------------------------------------------
     | API pública
     | ----------------------------------------------------------------- */

    /**
     * Construye la consulta con todos los filtros aplicados, sin ejecutarla.
     *
     * Úsalo cuando quieras seguir componiendo, hacer un ->cursor() para exportar
     * o inspeccionar el SQL generado.
     *
     * @param class-string<Model> $model
     * @param array<string, mixed> $data
     * @param array<string, mixed> $options
     * @return EloquentBuilder<Model>
     */
    public function query(string $model, array $data = [], array $options = []): EloquentBuilder
    {
        return $this->prepare($model, $data, $options)->modelQuery;
    }

    /**
     * Ejecuta la búsqueda: colección paginada o completa según los datos.
     *
     * @param class-string<Model> $model
     * @param array<string, mixed> $data
     * @param array<string, mixed> $options
     * @return \Illuminate\Contracts\Pagination\Paginator|CursorPaginator|Collection
     */
    public function get(string $model, array $data = [], array $options = [])
    {
        $this->prepare($model, $data, $options);

        $start = microtime(true);
        $result = $this->executeSearch();
        $elapsed = (microtime(true) - $start) * 1000;

        $this->recordExecution($result, $elapsed);

        return $result;
    }

    /**
     * Solo el total, sin traer filas. Mucho más barato que get()->count().
     *
     * @param class-string<Model> $model
     * @param array<string, mixed> $data
     * @param array<string, mixed> $options
     */
    public function count(string $model, array $data = [], array $options = []): int
    {
        return $this->query($model, $data, $options)->toBase()->getCountForPagination();
    }

    /**
     * ¿Hay al menos un resultado? Se traduce a un EXISTS, no a un COUNT.
     *
     * @param class-string<Model> $model
     * @param array<string, mixed> $data
     * @param array<string, mixed> $options
     */
    public function exists(string $model, array $data = [], array $options = []): bool
    {
        return $this->query($model, $data, $options)->exists();
    }

    /**
     * El primer resultado, con LIMIT 1.
     *
     * @param class-string<Model> $model
     * @param array<string, mixed> $data
     * @param array<string, mixed> $options
     */
    public function first(string $model, array $data = [], array $options = []): ?Model
    {
        return $this->query($model, $data, $options)->first();
    }

    /**
     * Recorre los resultados por lotes sin cargarlos todos en memoria.
     *
     * Es lo que deberían usar los exports: un LazyCollection mantiene la RAM
     * plana aunque la consulta devuelva cientos de miles de filas.
     *
     * @param class-string<Model> $model
     * @param array<string, mixed> $data
     * @param array<string, mixed> $options
     * @return LazyCollection<int, Model>
     */
    public function lazy(string $model, array $data = [], array $options = [], int $chunkSize = 1000)
    {
        return $this->query($model, $data, $options)->lazy($chunkSize);
    }

    /**
     * Como lazy(), pero paginando por clave primaria en vez de por OFFSET.
     * Es la opción correcta para tablas grandes: el coste no crece con la
     * profundidad del recorrido. Requiere que no impongas tu propio orden.
     *
     * @param class-string<Model> $model
     * @param array<string, mixed> $data
     * @param array<string, mixed> $options
     * @return LazyCollection<int, Model>
     */
    public function lazyById(string $model, array $data = [], array $options = [], int $chunkSize = 1000)
    {
        return $this->query($model, $data, $options)->lazyById($chunkSize);
    }

    /**
     * Un cursor de PHP sobre los resultados. Una sola consulta, una fila
     * hidratada a la vez.
     *
     * @param class-string<Model> $model
     * @param array<string, mixed> $data
     * @param array<string, mixed> $options
     * @return LazyCollection<int, Model>
     */
    public function cursor(string $model, array $data = [], array $options = [])
    {
        return $this->query($model, $data, $options)->cursor();
    }

    /* -----------------------------------------------------------------
     | Configuración
     | ----------------------------------------------------------------- */

    /**
     * Fija opciones que persisten entre búsquedas de esta instancia.
     *
     * @param array<string, mixed> $options
     */
    public function setOptions(array $options = []): static
    {
        $this->stickyOptions = array_merge($this->stickyOptions, $options);

        return $this->useOptions($this->stickyOptions);
    }

    /**
     * Aplica un juego de opciones a la búsqueda en curso.
     *
     * @param array<string, mixed> $options
     */
    protected function useOptions(array $options): static
    {
        $this->options = $options;

        if (array_key_exists('basePath', $options)) {
            $this->basePath = $options['basePath'];
        }

        if (array_key_exists('filtersPath', $options)) {
            $this->filtersPath = $options['filtersPath'];
        }

        if (array_key_exists('filtersNamespace', $options)) {
            $this->filtersNamespace = $options['filtersNamespace'];
        }

        return $this;
    }

    /**
     * Ruta base para el modo legado de filtersPath.
     *
     * Se guarda como opción sticky para que siga valiendo en llamadas
     * sucesivas, que es como se usaba en v2.
     */
    public function setBasePath(string $basePath): static
    {
        $this->stickyOptions['basePath'] = $basePath;

        return $this->useOptions($this->stickyOptions);
    }

    /* -----------------------------------------------------------------
     | Preparación
     | ----------------------------------------------------------------- */

    /**
     * @param class-string<Model> $model
     * @param array<string, mixed> $data
     * @param array<string, mixed> $options
     */
    protected function prepare(string $model, array $data, array $options): static
    {
        return $this->reset()
            ->useOptions(array_merge($this->stickyOptions, $options))
            ->setModelClass($model)
            ->setModelName()
            ->setModelQuery()
            ->setFilters()
            ->setFiltersRealPath()
            ->setData($data)
            ->applyFilters()
            ->applyQueryOptions()
            ->applyStableOrder();
    }

    /**
     * Limpia el estado de la búsqueda anterior. Lo fijado con setOptions() o
     * setBasePath() sobrevive, porque useOptions() lo vuelve a aplicar desde
     * $stickyOptions; lo que se pasó a un get() concreto, no.
     */
    protected function reset(): static
    {
        $this->basePath = null;
        $this->modelClass = null;
        $this->modelClassName = null;
        $this->modelName = null;
        $this->modelQuery = null;
        $this->filters = [];
        $this->appliedFilters = [];
        $this->filtersRealPath = null;
        $this->data = null;
        $this->options = [];

        // Vuelven al valor por defecto; useOptions() reaplica las sticky.
        $this->filtersPath = self::DEFAULT_FILTERS_PATH;
        $this->filtersNamespace = self::DEFAULT_FILTERS_NAMESPACE;

        return $this;
    }

    /**
     * @param class-string<Model> $model
     */
    protected function setModelClass(string $model): static
    {
        if (! class_exists($model)) {
            throw new InvalidArgumentException(
                "SearchSurge: la clase de modelo [{$model}] no existe."
            );
        }

        // new es más barato que resolver por reflexión en el contenedor; solo
        // pasamos por el contenedor cuando el modelo está realmente enlazado.
        $instance = $this->container->bound($model)
            ? $this->container->make($model)
            : new $model;

        if (! $instance instanceof Model) {
            throw new InvalidArgumentException(
                "SearchSurge: [{$model}] debe ser un modelo de Eloquent."
            );
        }

        $this->modelClass = $instance;
        $this->modelClassName = $model;

        return $this;
    }

    protected function setModelName(): static
    {
        $this->modelName = class_basename($this->modelClass);

        return $this;
    }

    protected function setModelQuery(): static
    {
        $query = $this->options['query'] ?? null;

        if ($query instanceof EloquentBuilder) {
            $this->modelQuery = $query;

            return $this;
        }

        $this->modelQuery = $this->modelClass->newQuery();

        return $this;
    }

    protected function setFilters(): static
    {
        $options = $this->options;

        if ($this->basePath !== null && ! array_key_exists('basePath', $options)) {
            $options['basePath'] = $this->basePath;
        }

        $this->filters = $this->registry->resolve($this->modelClassName, $options);

        return $this;
    }

    /**
     * Deduce el namespace y el directorio reales de los filtros a partir de lo
     * que se resolvió. Se siguen exponiendo en $data porque Utils\Managed y
     * algunos filtros de terceros los leen.
     */
    protected function setFiltersRealPath(): static
    {
        $first = $this->filters[0] ?? null;

        if ($first !== null) {
            $namespace = substr($first, 0, (int) strrpos($first, '\\'));

            // El namespace de un filtro es <FiltersNamespace>\<Modelo>.
            $this->filtersNamespace = substr($namespace, 0, (int) strrpos($namespace, '\\'));

            try {
                $file = (new \ReflectionClass($first))->getFileName();
                $this->filtersRealPath = $file === false ? null : dirname($file);
            } catch (\ReflectionException) {
                $this->filtersRealPath = null;
            }

            return $this;
        }

        // Sin filtros resueltos, reconstruimos la ruta legada por si acaso.
        $base = $this->basePath ?? (function_exists('base_path') ? base_path().DIRECTORY_SEPARATOR : '');

        $this->filtersRealPath = $base.$this->filtersPath.DIRECTORY_SEPARATOR.$this->modelName;

        return $this;
    }

    /**
     * @param array<string, mixed> $data
     */
    protected function setData(array $data): static
    {
        $this->data = new DataContainer(array_merge($data, [
            'model' => $this->modelName,
            'modelClass' => $this->modelClass,
            'modelClassName' => $this->modelClassName,
            'filtersRealPath' => $this->filtersRealPath,
            'filtersNamespace' => $this->filtersNamespace,
            'managedFilterClass' => $this->managedFilterClass(),
        ]));

        return $this;
    }

    /**
     * Busca el filtro de autorización entre los ya resueltos antes de caer en
     * la convención de nombres.
     */
    protected function managedFilterClass(): string
    {
        foreach ($this->filters as $filter) {
            if (FilterMeta::isCritical($filter) || str_ends_with($filter, '\\ManagedFilter')) {
                return $filter;
            }
        }

        return $this->filtersNamespace.'\\'.$this->modelName.'\\ManagedFilter';
    }

    /* -----------------------------------------------------------------
     | Aplicación de filtros
     | ----------------------------------------------------------------- */

    protected function applyFilters(): static
    {
        $strict = (bool) ($this->options['strict']
            ?? $this->config->get('search-surge.strict', false));

        foreach ($this->filters as $filter) {
            // Si el filtro declara qué claves le interesan y ninguna viene en
            // los datos, no hay nada que aplicar: nos ahorramos la llamada y,
            // sobre todo, cualquier join o subconsulta que fuera a añadir.
            $keys = FilterMeta::keys($filter);

            if ($keys !== null && ! $this->data->anyFilled($keys)) {
                continue;
            }

            $this->appliedFilters[] = $filter;

            try {
                $result = $filter::apply($this->modelQuery, $this->data);

                // Aceptamos también filtros que mutan el builder sin devolverlo.
                if ($result instanceof EloquentBuilder) {
                    $this->modelQuery = $result;
                }
            } catch (Throwable $e) {
                // Un filtro de autorización que revienta no puede degradar en
                // "devuelve todo": eso sería una fuga de datos, no un aviso.
                if ($strict || FilterMeta::isCritical($filter)) {
                    throw $e;
                }

                Log::error("SearchSurge: el filtro [{$filter}] falló y se omitió.", [
                    'model' => $this->modelClassName,
                    'exception' => $e,
                ]);
            }
        }

        return $this;
    }

    /**
     * Opciones que controla el desarrollador (nunca la petición): columnas,
     * relaciones a precargar y conteos.
     */
    protected function applyQueryOptions(): static
    {
        if (! empty($this->options['with'])) {
            $this->modelQuery->with($this->options['with']);
        }

        if (! empty($this->options['withCount'])) {
            $this->modelQuery->withCount($this->options['withCount']);
        }

        if (! empty($this->options['withoutGlobalScopes'])) {
            $scopes = $this->options['withoutGlobalScopes'];

            $this->modelQuery->withoutGlobalScopes(is_array($scopes) ? $scopes : null);
        }

        $columns = $this->columns();

        if ($columns !== ['*']) {
            $this->modelQuery->select($columns);
        }

        return $this;
    }

    /**
     * Añade la clave primaria como último criterio de desempate.
     *
     * Un ORDER BY sobre una columna no única no define un orden total. Con
     * LIMIT/OFFSET eso no es cosmetico: dos filas con el mismo created_at
     * pueden salir en distinto orden en dos consultas, y entonces la página 2
     * repite una fila que ya salió en la página 1 y se salta otra. Cuanto más
     * grande es la tabla, más probable es el empate y más se nota.
     *
     * Solo se añade cuando YA hay un ORDER BY: si la consulta no ordenaba, no
     * le imponemos un orden que podría cambiar el plan de ejecución.
     */
    protected function applyStableOrder(): static
    {
        if (! $this->config->get('search-surge.pagination.stable_order', true)) {
            return $this;
        }

        if (($this->options['stableOrder'] ?? true) === false) {
            return $this;
        }

        $query = $this->modelQuery->getQuery();
        $orders = $query->orders ?? [];

        // Sin orden previo no tocamos nada; con unions, tampoco.
        if ($orders === [] || ! empty($query->unionOrders)) {
            return $this;
        }

        $key = $this->modelClass->getKeyName();
        $qualified = $this->modelClass->getQualifiedKeyName();
        $direction = 'asc';

        foreach ($orders as $order) {
            // Un ORDER BY crudo puede contener cualquier cosa: no lo analizamos.
            if (! isset($order['column'])) {
                return $this;
            }

            if ($order['column'] === $key || $order['column'] === $qualified) {
                return $this; // Ya hay un orden total.
            }

            $direction = $order['direction'] ?? $direction;
        }

        $this->modelQuery->orderBy($qualified, $direction);

        return $this;
    }

    /* -----------------------------------------------------------------
     | Ejecución
     | ----------------------------------------------------------------- */

    /**
     * @return \Illuminate\Contracts\Pagination\Paginator|CursorPaginator|Collection
     */
    protected function executeSearch()
    {
        $perPage = $this->resolvePerPage();

        if ($perPage === 0) {
            return $this->modelQuery->get($this->columns());
        }

        $pageName = (string) $this->config->get('search-surge.pagination.page_name', 'page');

        return match ($this->paginator()) {
            'simple' => $this->modelQuery->simplePaginate($perPage, $this->columns(), $pageName, $this->page($pageName)),
            'cursor' => $this->modelQuery->cursorPaginate($perPage, $this->columns(), 'cursor', $this->resolveCursor()),
            default => $this->lengthAwarePaginate($perPage, $pageName),
        };
    }

    /**
     * Paginador con total, con la opción de cachear el COUNT(*).
     *
     * En una tabla de millones de filas el COUNT(*) del paginador suele costar
     * más que la página de datos: la página son 20 filas por índice, el conteo
     * recorre todas las que cumplen el filtro. Y se repite en cada carga.
     *
     * Con 'count_cache' se reutiliza el total durante unos segundos para la
     * misma combinación de filtros. El precio es que el total puede ir
     * ligeramente desfasado; el número de páginas de un listado admite eso
     * mucho mejor que un COUNT(*) de 10M filas por pulsación.
     *
     * @return LengthAwarePaginator
     */
    protected function lengthAwarePaginate(int $perPage, string $pageName)
    {
        $ttl = $this->countCacheTtl();

        if ($ttl === null) {
            return $this->modelQuery->paginate($perPage, $this->columns(), $pageName, $this->page($pageName));
        }

        $page = $this->page($pageName);
        $total = $this->cachedTotal($ttl);

        $results = $total > 0
            ? $this->modelQuery->forPage($page, $perPage)->get($this->columns())
            : $this->modelClass->newCollection();

        return $this->container->makeWith(LengthAwarePaginator::class, [
            'items' => $results,
            'total' => $total,
            'perPage' => $perPage,
            'currentPage' => $page,
            'options' => [
                'path' => Paginator::resolveCurrentPath(),
                'pageName' => $pageName,
            ],
        ]);
    }

    /**
     * El total, cacheado por la firma exacta de la consulta.
     */
    protected function cachedTotal(int $ttl): int
    {
        $base = $this->modelQuery->toBase();

        $key = $this->config->get('search-surge.cache.prefix', 'search-surge:filters:')
            .'count:'.sha1($base->toSql().'|'.serialize($base->getBindings()));

        $store = $this->container->make('cache')->store(
            $this->config->get('search-surge.cache.store')
        );

        return (int) $store->remember($key, $ttl, static fn (): int => $base->getCountForPagination());
    }

    /**
     * TTL de la caché de conteo en segundos, o null si está desactivada.
     */
    protected function countCacheTtl(): ?int
    {
        $ttl = $this->options['countCache']
            ?? $this->config->get('search-surge.pagination.count_cache');

        if ($ttl === null || $ttl === false) {
            return null;
        }

        $ttl = (int) $ttl;

        return $ttl > 0 ? $ttl : null;
    }

    /**
     * Página solicitada, con el tope de profundidad aplicado.
     *
     * OFFSET no es gratis: para servir la página 100.000 el motor tiene que
     * recorrer y descartar todas las filas anteriores. Un tope evita que una
     * petición (o un crawler siguiendo enlaces de paginación) convierta eso en
     * una consulta de varios segundos.
     */
    /**
     * Cursor solicitado.
     *
     * Se lee primero de $data para que el patron habitual -pasarle
     * $request->all() al builder- funcione igual que con 'page'. Si no viene,
     * se deja que Laravel lo resuelva de la peticion.
     */
    protected function resolveCursor(): ?string
    {
        $cursor = $this->data->get('cursor');

        return is_string($cursor) && $cursor !== '' ? $cursor : null;
    }

    protected function page(string $pageName): int
    {
        $page = (int) ($this->data->get($pageName) ?? Paginator::resolveCurrentPage($pageName));

        if ($page < 1) {
            $page = 1;
        }

        $max = $this->options['maxPage'] ?? $this->config->get('search-surge.pagination.max_page');

        if ($max !== null && $page > (int) $max) {
            throw new PageLimitExceededException($page, (int) $max);
        }

        return $page;
    }

    /**
     * Emite el evento de busqueda ejecutada y registra las lentas.
     *
     * Se hace aqui y no con el log de consultas de Laravel porque aqui se sabe
     * algo que alli no: que filtros entraron. Cuando un listado se degrada, esa
     * es justo la informacion que falta.
     */
    protected function recordExecution(mixed $result, float $elapsed): void
    {
        $threshold = $this->options['slowThreshold']
            ?? $this->config->get('search-surge.observability.slow_threshold');

        $emit = (bool) ($this->options['events']
            ?? $this->config->get('search-surge.observability.events', true));

        if (! $emit && $threshold === null) {
            return;
        }

        $event = new SearchExecuted(
            model: (string) $this->modelClassName,
            filters: $this->appliedFilters,
            parameters: array_values(array_diff(
                $this->data->keys(),
                ['model', 'modelClass', 'modelClassName', 'filtersRealPath', 'filtersNamespace', 'managedFilterClass']
            )),
            sql: $this->modelQuery->toSql(),
            bindings: $this->modelQuery->getBindings(),
            milliseconds: $elapsed,
            results: $this->countOf($result),
        );

        if ($threshold !== null && $event->isSlow((float) $threshold)) {
            Log::warning('SearchSurge: busqueda lenta.', $event->context());
        }

        if ($emit && $this->container->bound('events')) {
            $this->container->make('events')->dispatch($event);
        }
    }

    protected function countOf(mixed $result): ?int
    {
        return match (true) {
            $result instanceof \Countable => count($result),
            is_array($result) => count($result),
            default => null,
        };
    }

    /**
     * Cuántos elementos por página. Devuelve 0 cuando se pidió "todo".
     */
    protected function resolvePerPage(): int
    {
        $raw = $this->options['perPage'] ?? $this->data->get('paginate');

        // Compatible con el comportamiento histórico: 0, '0' y false traen todo.
        if ($raw === 0 || $raw === '0' || $raw === false || $raw === 'false') {
            return 0;
        }

        $default = (int) $this->config->get('search-surge.pagination.per_page', 10);

        if (! is_numeric($raw)) {
            return $default;
        }

        $perPage = (int) $raw;

        if ($perPage < 1) {
            return $default;
        }

        $max = $this->options['maxPerPage']
            ?? $this->config->get('search-surge.pagination.max_per_page', 1000);

        // El tope protege de un ?paginate=999999 que reviente memoria y base.
        return $max === null ? $perPage : min($perPage, (int) $max);
    }

    protected function paginator(): string
    {
        $mode = $this->options['paginator']
            ?? $this->data->get('paginator')
            ?? $this->config->get('search-surge.pagination.paginator', 'length_aware');

        return in_array($mode, ['simple', 'cursor'], true) ? $mode : 'length_aware';
    }

    /**
     * Columnas a seleccionar. Solo se leen de $options porque vienen del
     * desarrollador; aceptar identificadores de columna desde la petición sería
     * abrir la puerta a inyección.
     *
     * @return array<int, string>
     */
    protected function columns(): array
    {
        $columns = $this->options['columns'] ?? null;

        if (empty($columns)) {
            return ['*'];
        }

        $columns = array_values(array_filter(
            (array) $columns,
            static fn ($column): bool => is_string($column)
                && preg_match('/^(?:[A-Za-z_][A-Za-z0-9_]*\.)?(?:\*|[A-Za-z_][A-Za-z0-9_]*)$/', $column) === 1
        ));

        return $columns === [] ? ['*'] : $columns;
    }
}
