<?php

namespace Innoboxrr\SearchSurge\Console;

use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;
use Innoboxrr\SearchSurge\Search\Support\ComposerLocator;

/**
 * Crea un filtro en el sitio donde SearchSurge lo va a buscar.
 *
 *   php artisan search-surge:filter "App\Models\User" Name
 *   php artisan search-surge:filter "Innoboxrr\Deals\Models\Deal" Global --type=text
 *
 * La ruta no se pide: se deduce del namespace del modelo y del autoloader, que
 * es exactamente la misma regla que usa el descubrimiento. Así el archivo cae
 * donde tiene que caer tanto en una app como dentro de un paquete.
 */
class MakeFilterCommand extends Command
{
    protected $signature = 'search-surge:filter
                            {model : Clase del modelo}
                            {name : Nombre del filtro, sin el sufijo (ej. "Name")}
                            {--type=basic : basic | text | date | engine | managed}
                            {--force : Sobrescribir si ya existe}';

    protected $description = 'Crea un filtro para un modelo, en el sitio donde SearchSurge lo busca';

    public function handle(Filesystem $files): int
    {
        $model = ltrim((string) $this->argument('model'), '\\');

        if (! class_exists($model)) {
            $this->components->error("La clase [{$model}] no existe.");

            return self::FAILURE;
        }

        $modelName = class_basename($model);
        $modelNamespace = substr($model, 0, (int) strrpos($model, '\\'));
        $suffix = (string) config('search-surge.filters.suffix', 'Filters');
        $namespace = $modelNamespace . '\\' . $suffix . '\\' . $modelName;

        $directory = $this->directoryFor($namespace, $modelNamespace, $suffix, $modelName);

        if ($directory === null) {
            $this->components->error(
                "No se pudo deducir donde vive [{$namespace}]. "
                . 'Comprueba que el namespace del modelo esta en el autoloader PSR-4.'
            );

            return self::FAILURE;
        }

        $class = $this->className((string) $this->argument('name'));
        $path = $directory . DIRECTORY_SEPARATOR . $class . '.php';

        if ($files->exists($path) && ! $this->option('force')) {
            $this->components->error("Ya existe: {$path}");

            return self::FAILURE;
        }

        $files->ensureDirectoryExists($directory);
        $files->put($path, $this->render($namespace, $class));

        $this->components->info("Filtro creado: {$namespace}\\{$class}");
        $this->components->twoColumnDetail('Archivo', $path);
        $this->components->twoColumnDetail(
            'Se aplicara',
            'sin registrar nada: la convencion lo encuentra solo'
        );

        return self::SUCCESS;
    }

    /**
     * Dónde crear el archivo.
     *
     * Si el namespace de filtros ya existe, se usa. Si no, se deriva del
     * directorio del propio modelo, que sí está en el autoloader.
     */
    protected function directoryFor(
        string $namespace,
        string $modelNamespace,
        string $suffix,
        string $modelName
    ): ?string {
        $existing = ComposerLocator::directoryFor($namespace);

        if ($existing !== null) {
            return $existing;
        }

        $modelDirectory = ComposerLocator::directoryFor($modelNamespace);

        if ($modelDirectory === null) {
            return null;
        }

        return $modelDirectory . DIRECTORY_SEPARATOR . $suffix . DIRECTORY_SEPARATOR . $modelName;
    }

    protected function className(string $name): string
    {
        $name = str_replace(['/', '\\'], '', trim($name));
        $name = preg_replace('/Filter$/i', '', $name) ?? $name;

        return ucfirst($name) . 'Filter';
    }

    protected function render(string $namespace, string $class): string
    {
        $key = lcfirst(preg_replace('/Filter$/', '', $class) ?? $class);
        $snake = strtolower((string) preg_replace('/(?<!^)[A-Z]/', '_$0', $key));

        return match ($this->option('type')) {
            'text' => $this->textStub($namespace, $class),
            'date' => $this->dateStub($namespace, $class, $snake),
            'engine' => $this->engineStub($namespace, $class),
            'managed' => $this->managedStub($namespace, $class),
            default => $this->basicStub($namespace, $class, $snake),
        };
    }

    protected function basicStub(string $namespace, string $class, string $key): string
    {
        return <<<PHP
        <?php

        namespace {$namespace};

        use Illuminate\\Database\\Eloquent\\Builder;
        use Innoboxrr\\SearchSurge\\Search\\Contracts\\Filter;
        use Innoboxrr\\SearchSurge\\Search\\Support\\DataContainer;
        use Innoboxrr\\SearchSurge\\Search\\Utils\\Order;

        class {$class} implements Filter
        {
            /**
             * Claves que consume este filtro. Si no viene ninguna, SearchSurge
             * no lo ejecuta y se ahorra el trabajo. Declaralas TODAS, incluidas
             * las de ordenamiento.
             *
             * @var array<int, string>
             */
            public static array \$keys = ['{$key}', ...Order::KEYS];

            public static function apply(Builder \$query, DataContainer \$data)
            {
                if (\$data->filled('{$key}')) {
                    \$query->where('{$key}', \$data->string('{$key}'));
                }

                return Order::orderBy(\$query, \$data, '{$key}');
            }
        }

        PHP;
    }

    protected function textStub(string $namespace, string $class): string
    {
        return <<<PHP
        <?php

        namespace {$namespace};

        use Illuminate\\Database\\Eloquent\\Builder;
        use Innoboxrr\\SearchSurge\\Search\\Contracts\\Filter;
        use Innoboxrr\\SearchSurge\\Search\\Support\\DataContainer;
        use Innoboxrr\\SearchSurge\\Search\\Utils\\TextSearch;

        class {$class} implements Filter
        {
            /** @var array<int, string> */
            public static array \$keys = ['q'];

            public static function apply(Builder \$query, DataContainer \$data)
            {
                // prefix() es la unica variante de LIKE que usa el indice.
                // Sobre 1M de filas: 15 ms frente a los 27 s de contains().
                //
                // Si necesitas buscar por el medio de la cadena, usa
                // fullText() (requiere un indice FULLTEXT) o delega en un motor
                // externo con un filtro que extienda EngineFilter.
                return TextSearch::prefix(\$query, \$data, 'q', ['name']);
            }
        }

        PHP;
    }

    protected function dateStub(string $namespace, string $class, string $column): string
    {
        return <<<PHP
        <?php

        namespace {$namespace};

        use Illuminate\\Database\\Eloquent\\Builder;
        use Innoboxrr\\SearchSurge\\Search\\Contracts\\Filter;
        use Innoboxrr\\SearchSurge\\Search\\Support\\DataContainer;
        use Innoboxrr\\SearchSurge\\Search\\Utils\\DateFilterQuery;
        use Innoboxrr\\SearchSurge\\Search\\Utils\\Order;

        class {$class} implements Filter
        {
            /** @var array<int, string> */
            public static array \$keys = [
                '{$column}',
                '{$column}_operator',
                '{$column}_start_date',
                '{$column}_end_date',
                'operator',
                ...Order::KEYS,
            ];

            public static function apply(Builder \$query, DataContainer \$data)
            {
                \$query = DateFilterQuery::apply(\$query, \$data, '{$column}');

                return Order::orderBy(\$query, \$data, '{$column}');
            }
        }

        PHP;
    }

    protected function engineStub(string $namespace, string $class): string
    {
        return <<<PHP
        <?php

        namespace {$namespace};

        use Innoboxrr\\SearchSurge\\Search\\Filters\\EngineFilter;

        /**
         * Delega la busqueda de texto en un motor externo.
         *
         * Con Laravel Scout (Algolia, Meilisearch, Typesense, Elasticsearch via
         * driver) no hace falta nada mas: el modelo tiene que usar el trait
         * Searchable.
         *
         * Con un cliente propio, sobrescribe ids().
         */
        class {$class} extends EngineFilter
        {
            /**
             * Cuantos ids se le piden al motor. Es la decision que da forma al
             * hibrido: bajo, el motor pagina de hecho; alto, manda SQL y los
             * totales son exactos pero el IN (...) crece.
             */
            protected static function limit(): int
            {
                return 500;
            }
        }

        PHP;
    }

    protected function managedStub(string $namespace, string $class): string
    {
        return <<<PHP
        <?php

        namespace {$namespace};

        use Innoboxrr\\SearchSurge\\Search\\Utils\\Managed;

        /**
         * Acota la consulta a lo que el usuario puede administrar.
         *
         * SearchSurge trata a los descendientes de Managed como criticos: se
         * aplican los primeros y, si lanzan, la excepcion se propaga en vez de
         * tragarse. Devolver resultados sin acotar porque el filtro de permisos
         * fallo seria una fuga de datos.
         */
        class {$class} extends Managed
        {
            public static function canView(\$query, \$user, array \$args = [])
            {
                return \$query->where('user_id', \$user->getAuthIdentifier());
            }
        }

        PHP;
    }
}
