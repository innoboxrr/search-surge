<?php

namespace Innoboxrr\SearchSurge\Console;

use Illuminate\Database\Eloquent\Model;
use Innoboxrr\SearchSurge\Search\Support\ComposerLocator;
use Innoboxrr\SearchSurge\Search\Support\FilterRegistry;

/**
 * Encuentra los modelos de Eloquent del proyecto y de sus paquetes.
 *
 * Solo lo usan los comandos de consola: en un request nunca se escanea nada.
 */
class ModelScanner
{
    public function __construct(protected FilterRegistry $registry)
    {
    }

    /**
     * Todos los modelos candidatos, deduplicados.
     *
     * @param array<int, string> $extraNamespaces
     * @return array<int, class-string<Model>>
     */
    public function models(array $extraNamespaces = []): array
    {
        $namespaces = array_unique(array_merge(
            array_keys($this->registry->namespaces()),
            $this->modelNamespacesFromRegisteredFilters(),
            $extraNamespaces,
            // Cualquier "X\Models" que exista en el autoloader, sea de la app o
            // de un paquete. Así un paquete que sigue la convención entra en el
            // manifiesto sin registrar nada.
            ComposerLocator::namespacesEndingIn('Models'),
        ));

        $models = array_keys($this->registry->registered());

        foreach ($namespaces as $namespace) {
            foreach ($this->classesIn($namespace) as $class) {
                $models[] = $class;
            }
        }

        $models = array_values(array_unique(array_filter(
            $models,
            fn (string $class): bool => $this->isModel($class)
        )));

        sort($models, SORT_STRING);

        return $models;
    }

    /**
     * Deduce los namespaces de modelos a partir de los mapas registrados.
     *
     * @return array<int, string>
     */
    protected function modelNamespacesFromRegisteredFilters(): array
    {
        $namespaces = [];

        foreach (array_keys($this->registry->registered()) as $model) {
            $position = strrpos($model, '\\');

            if ($position !== false) {
                $namespaces[] = substr($model, 0, $position);
            }
        }

        return array_values(array_unique($namespaces));
    }

    /**
     * Clases declaradas directamente en un namespace (sin recursión: los
     * modelos de Laravel viven planos en App\Models o en Paquete\Models).
     *
     * @return array<int, string>
     */
    protected function classesIn(string $namespace): array
    {
        $classes = [];

        foreach (ComposerLocator::directoriesFor($namespace) as $directory) {
            foreach (glob($directory . DIRECTORY_SEPARATOR . '*.php') ?: [] as $file) {
                $classes[] = trim($namespace, '\\') . '\\' . basename($file, '.php');
            }
        }

        return $classes;
    }

    protected function isModel(string $class): bool
    {
        if (! class_exists($class)) {
            return false;
        }

        try {
            $reflection = new \ReflectionClass($class);
        } catch (\ReflectionException) {
            return false;
        }

        return $reflection->isSubclassOf(Model::class)
            && ! $reflection->isAbstract();
    }
}
