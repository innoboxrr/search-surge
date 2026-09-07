<?php

namespace Innoboxrr\SearchSurge\Search\Utils;

use Illuminate\Database\Eloquent\Builder;
use Innoboxrr\SearchSurge\Search\Support\DataContainer;

/**
 * Búsqueda de texto sobre una o varias columnas.
 *
 * Existe porque la diferencia entre las tres formas de hacer esto no es de
 * estilo, es de tres órdenes de magnitud. Medido en MySQL 8 sobre 1.000.000 de
 * filas con la columna indexada:
 *
 *     prefix()    LIKE 'zapato%'          14,7 ms   range sobre el índice
 *     fullText()  MATCH ... AGAINST      356,0 ms   índice FULLTEXT
 *     contains()  LIKE '%zapato%'     26.912,0 ms   recorre el índice entero
 *
 * Y un OR de dos contains() sobre columnas distintas hace que el motor
 * abandone los índices del todo: 11.161 ms con un full table scan.
 *
 * La regla práctica: usa prefix() para autocompletados, fullText() para un
 * buscador de verdad, y contains() solo cuando la tabla sea pequeña y sepas
 * que lo va a seguir siendo.
 *
 * Las tres escapan los comodines del término. Sin eso, un `?q=%` se convierte
 * en "devuélvelo todo" y un `?q=_` casa con cualquier carácter: además de dar
 * resultados absurdos, es una forma barata de tumbar la base.
 */
class TextSearch
{
    /**
     * Caracter de escape que se usa en las clausulas LIKE.
     */
    protected const ESCAPE_CHAR = '~';

    /**
     * Caracteres que LIKE interpreta y que en un término escrito por el usuario
     * deben ser literales.
     *
     * El escape no usa barra invertida a propósito. Parece la opción obvia,
     * pero no es portable: MySQL la trata como escape dentro de los literales y
     * obliga a escribir `ESCAPE '\\'`, mientras que SQLite no procesa escapes en
     * literales y eso mismo le da error de "single character". Un carácter sin
     * significado especial en ningún motor evita esa rama.
     */
    protected const WILDCARDS = ['%', '_'];

    /**
     * Drivers en los que Laravel sabe generar búsqueda de texto completo.
     */
    protected const FULLTEXT_DRIVERS = ['mysql', 'mariadb', 'pgsql'];

    /* -----------------------------------------------------------------
     | Estrategias
     | ----------------------------------------------------------------- */

    /**
     * LIKE 'termino%'. La única variante de LIKE que puede usar un índice.
     *
     * @param Builder<\Illuminate\Database\Eloquent\Model> $query
     * @param array<int, string> $columns
     * @param array<string, mixed> $options
     * @return Builder<\Illuminate\Database\Eloquent\Model>
     */
    public static function prefix(Builder $query, $data, string $key, array $columns, array $options = []): Builder
    {
        return self::like($query, $data, $key, $columns, $options, '%s%%');
    }

    /**
     * LIKE '%termino%'. Nunca usa un índice, en ningún motor.
     *
     * @param Builder<\Illuminate\Database\Eloquent\Model> $query
     * @param array<int, string> $columns
     * @param array<string, mixed> $options
     * @return Builder<\Illuminate\Database\Eloquent\Model>
     */
    public static function contains(Builder $query, $data, string $key, array $columns, array $options = []): Builder
    {
        return self::like($query, $data, $key, $columns, $options, '%%%s%%');
    }

    /**
     * LIKE '%termino'. Igual de cara que contains() y menos útil; está por
     * simetría, para búsquedas por sufijo (dominios, extensiones).
     *
     * @param Builder<\Illuminate\Database\Eloquent\Model> $query
     * @param array<int, string> $columns
     * @param array<string, mixed> $options
     * @return Builder<\Illuminate\Database\Eloquent\Model>
     */
    public static function suffix(Builder $query, $data, string $key, array $columns, array $options = []): Builder
    {
        return self::like($query, $data, $key, $columns, $options, '%%%s');
    }

    /**
     * MATCH ... AGAINST en MySQL, to_tsquery en PostgreSQL.
     *
     * Requiere un índice FULLTEXT sobre esas columnas. Crearlo sobre una tabla
     * grande no es gratis ni instantáneo: sobre 1M de filas tardó 7 minutos y
     * bloqueó la tabla. Hazlo en ventana de mantenimiento.
     *
     * Opciones adicionales:
     *   mode     -> 'natural' (por defecto) o 'boolean'
     *   expanded -> query expansion de MySQL
     *   fallback -> qué hacer si el driver no lo soporta: 'contains' (por
     *               defecto), 'prefix' o 'throw'
     *
     * @param Builder<\Illuminate\Database\Eloquent\Model> $query
     * @param array<int, string> $columns
     * @param array<string, mixed> $options
     * @return Builder<\Illuminate\Database\Eloquent\Model>
     */
    public static function fullText(Builder $query, $data, string $key, array $columns, array $options = []): Builder
    {
        $term = self::term($data, $key, $options);

        if ($term === null || $columns === []) {
            return $query;
        }

        if (! self::supportsFullText($query)) {
            return match ($options['fallback'] ?? 'contains') {
                'prefix' => self::prefix($query, $data, $key, $columns, $options),
                'throw' => throw new \RuntimeException(
                    'SearchSurge: el driver [' . self::driver($query) . '] no soporta busqueda de texto completo. '
                    . "Usa la opcion 'fallback' o cambia a prefix()/contains()."
                ),
                default => self::contains($query, $data, $key, $columns, $options),
            };
        }

        $fullTextOptions = array_intersect_key($options, ['mode' => null, 'expanded' => null, 'language' => null]);

        return $query->whereFullText(
            self::qualifyAll($query, $columns),
            $term,
            $fullTextOptions
        );
    }

    /* -----------------------------------------------------------------
     | Motor
     | ----------------------------------------------------------------- */

    /**
     * Aplica una de las variantes de LIKE.
     *
     * Cada palabra del término tiene que aparecer en alguna de las columnas
     * (modo 'all', por defecto), que es lo que espera quien escribe en una
     * caja de búsqueda. Con mode => 'any' basta con que aparezca una.
     *
     * @param Builder<\Illuminate\Database\Eloquent\Model> $query
     * @param array<int, string> $columns
     * @param array<string, mixed> $options
     * @return Builder<\Illuminate\Database\Eloquent\Model>
     */
    protected static function like(
        Builder $query,
        $data,
        string $key,
        array $columns,
        array $options,
        string $pattern
    ): Builder {
        $term = self::term($data, $key, $options);

        if ($term === null || $columns === []) {
            return $query;
        }

        $words = self::words($term, $options);
        $columns = self::qualifyAll($query, $columns);
        $any = ($options['mode'] ?? 'all') === 'any';

        $operator = self::operator($query, $options);

        return $query->where(function (Builder $outer) use ($words, $columns, $pattern, $any, $operator): void {
            foreach ($words as $index => $word) {
                $value = sprintf($pattern, self::escape($word));

                $group = function (Builder $inner) use ($columns, $value, $operator): void {
                    $grammar = $inner->getQuery()->getGrammar();

                    foreach ($columns as $position => $column) {
                        // ESCAPE explicito: sin el, SQLite no interpreta ningun
                        // caracter de escape y buscaria los comodines escapados
                        // de forma literal.
                        $sql = $grammar->wrap($column) . ' ' . $operator
                            . " ? escape '" . self::ESCAPE_CHAR . "'";

                        $position === 0
                            ? $inner->whereRaw($sql, [$value])
                            : $inner->orWhereRaw($sql, [$value]);
                    }
                };

                // El primer grupo entra con AND para no colgar de la nada.
                $any && $index > 0
                    ? $outer->orWhere($group)
                    : $outer->where($group);
            }
        });
    }

    /* -----------------------------------------------------------------
     | Términos
     | ----------------------------------------------------------------- */

    /**
     * El término normalizado, o null si no hay nada que buscar.
     *
     * @param array<string, mixed> $options
     */
    protected static function term($data, string $key, array $options): ?string
    {
        $raw = $data instanceof DataContainer ? $data->get($key) : ($data->{$key} ?? null);

        if (! is_scalar($raw)) {
            return null;
        }

        // Espacios repetidos colapsados: "  zapato   rojo " -> "zapato rojo".
        $term = trim(preg_replace('/\s+/u', ' ', (string) $raw) ?? '');

        if ($term === '') {
            return null;
        }

        $min = (int) ($options['minLength'] ?? self::config('text.min_length', 1));

        return mb_strlen($term) < $min ? null : $term;
    }

    /**
     * Palabras del término, acotadas en número.
     *
     * El tope importa: sin él, pegar un párrafo en la caja de búsqueda genera
     * un WHERE con cientos de grupos anidados.
     *
     * @param array<string, mixed> $options
     * @return array<int, string>
     */
    protected static function words(string $term, array $options): array
    {
        if (($options['split'] ?? true) === false) {
            return [$term];
        }

        $words = preg_split('/\s+/u', $term) ?: [$term];
        $max = (int) ($options['maxTerms'] ?? self::config('text.max_terms', 8));

        return $max > 0 ? array_slice($words, 0, $max) : $words;
    }

    /**
     * Neutraliza los comodines de LIKE dentro del término del usuario.
     */
    public static function escape(string $value): string
    {
        // El propio caracter de escape va primero; hacerlo despues duplicaria
        // los que introduce el escapado de los comodines.
        $value = str_replace(self::ESCAPE_CHAR, self::ESCAPE_CHAR . self::ESCAPE_CHAR, $value);

        foreach (self::WILDCARDS as $wildcard) {
            $value = str_replace($wildcard, self::ESCAPE_CHAR . $wildcard, $value);
        }

        return $value;
    }

    /**
     * LIKE o ILIKE.
     *
     * En MySQL la sensibilidad a mayusculas la decide la collation y LIKE ya es
     * insensible. En PostgreSQL LIKE si distingue, asi que hace falta ILIKE
     * para que la busqueda se comporte igual en los dos motores.
     *
     * @param Builder<\Illuminate\Database\Eloquent\Model> $query
     * @param array<string, mixed> $options
     */
    protected static function operator(Builder $query, array $options): string
    {
        if ($options['caseSensitive'] ?? false) {
            return 'like';
        }

        return self::driver($query) === 'pgsql' ? 'ilike' : 'like';
    }

    /* -----------------------------------------------------------------
     | Ayudas
     | ----------------------------------------------------------------- */

    /**
     * @param Builder<\Illuminate\Database\Eloquent\Model> $query
     * @param array<int, string> $columns
     * @return array<int, string>
     */
    protected static function qualifyAll(Builder $query, array $columns): array
    {
        $model = $query->getModel();

        return array_values(array_map(
            static fn (string $column): string => str_contains($column, '.')
                ? $column
                : $model->qualifyColumn($column),
            $columns
        ));
    }

    /**
     * @param Builder<\Illuminate\Database\Eloquent\Model> $query
     */
    public static function supportsFullText(Builder $query): bool
    {
        return in_array(self::driver($query), self::FULLTEXT_DRIVERS, true);
    }

    /**
     * @param Builder<\Illuminate\Database\Eloquent\Model> $query
     */
    protected static function driver(Builder $query): string
    {
        return (string) $query->getConnection()->getDriverName();
    }

    protected static function config(string $key, mixed $default): mixed
    {
        return function_exists('config')
            ? config('search-surge.' . $key, $default)
            : $default;
    }
}
