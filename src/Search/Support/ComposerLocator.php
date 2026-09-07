<?php

namespace Innoboxrr\SearchSurge\Search\Support;

use Composer\Autoload\ClassLoader;

/**
 * Traduce un namespace PSR-4 a los directorios reales que lo respaldan,
 * preguntándole al autoloader de Composer.
 *
 * Esto es lo que permite que un paquete no tenga que declarar rutas como
 * 'vendor/innoboxrr/laravel-blog/src/Models/Filters': da igual si el paquete
 * está instalado en vendor/, enlazado como path repository o movido de sitio,
 * porque el autoloader siempre sabe dónde vive de verdad.
 */
final class ComposerLocator
{
    /**
     * Prefijos PSR-4 ordenados de más específico a menos, cacheados por proceso.
     *
     * @var array<int, array{0: string, 1: array<int, string>}>|null
     */
    private static ?array $prefixes = null;

    /**
     * Directorios ya resueltos, cacheados por proceso.
     *
     * @var array<string, array<int, string>>
     */
    private static array $resolved = [];

    /**
     * Directorios existentes que respaldan un namespace.
     *
     * @return array<int, string>
     */
    public static function directoriesFor(string $namespace): array
    {
        $namespace = trim($namespace, '\\');

        if ($namespace === '') {
            return [];
        }

        if (isset(self::$resolved[$namespace])) {
            return self::$resolved[$namespace];
        }

        $directories = [];

        foreach (self::prefixes() as [$prefix, $paths]) {
            if (! str_starts_with($namespace.'\\', $prefix)) {
                continue;
            }

            // Lo que sobra del namespace después del prefijo se vuelve subruta.
            $relative = substr($namespace.'\\', strlen($prefix));
            $relative = str_replace('\\', DIRECTORY_SEPARATOR, rtrim($relative, '\\'));

            foreach ($paths as $path) {
                $directory = rtrim($path, DIRECTORY_SEPARATOR);

                if ($relative !== '') {
                    $directory .= DIRECTORY_SEPARATOR.$relative;
                }

                if (is_dir($directory)) {
                    $directories[] = $directory;
                }
            }

            // Los prefijos vienen ordenados de más largo a más corto: el primero
            // que resuelve a un directorio real es el correcto.
            if ($directories !== []) {
                break;
            }
        }

        return self::$resolved[$namespace] = array_values(array_unique($directories));
    }

    /**
     * El primer directorio que respalda un namespace, o null.
     */
    public static function directoryFor(string $namespace): ?string
    {
        return self::directoriesFor($namespace)[0] ?? null;
    }

    /**
     * ¿Hay algún autoloader de Composer disponible?
     */
    public static function available(): bool
    {
        return self::prefixes() !== [];
    }

    /**
     * Los namespaces raíz registrados en el autoloader, sin la barra final.
     *
     * @return array<int, string>
     */
    public static function rootNamespaces(): array
    {
        return array_map(
            static fn (array $entry): string => rtrim($entry[0], '\\'),
            self::prefixes()
        );
    }

    /**
     * Namespaces raíz que tienen un subnamespace con este nombre.
     *
     * Sirve para descubrir todos los "X\Models" del proyecto y sus paquetes sin
     * pedirle al desarrollador que los enumere.
     *
     * @return array<int, string>
     */
    public static function namespacesEndingIn(string $segment): array
    {
        $found = [];

        foreach (self::rootNamespaces() as $root) {
            if ($root === '') {
                continue;
            }

            $candidate = $root.'\\'.$segment;

            if (self::directoriesFor($candidate) !== []) {
                $found[] = $candidate;
            }
        }

        return array_values(array_unique($found));
    }

    /**
     * Olvida lo resuelto. Solo lo necesitan los tests y los comandos de caché.
     */
    public static function flush(): void
    {
        self::$prefixes = null;
        self::$resolved = [];
    }

    /**
     * @return array<int, array{0: string, 1: array<int, string>}>
     */
    private static function prefixes(): array
    {
        if (self::$prefixes !== null) {
            return self::$prefixes;
        }

        $prefixes = [];

        if (class_exists(ClassLoader::class) && method_exists(ClassLoader::class, 'getRegisteredLoaders')) {
            foreach (ClassLoader::getRegisteredLoaders() as $loader) {
                foreach ($loader->getPrefixesPsr4() as $prefix => $paths) {
                    $prefixes[$prefix] = array_merge($prefixes[$prefix] ?? [], (array) $paths);
                }
            }
        }

        // Más específico primero, para que 'App\Models\' gane sobre 'App\'.
        uksort($prefixes, static fn (string $a, string $b): int => strlen($b) <=> strlen($a));

        $normalized = [];

        foreach ($prefixes as $prefix => $paths) {
            $normalized[] = [$prefix, array_values(array_unique($paths))];
        }

        return self::$prefixes = $normalized;
    }
}
