<?php

namespace Innoboxrr\SearchSurge\Search\Support;

use Illuminate\Database\Eloquent\Builder;

/**
 * Analiza la consulta que produce una búsqueda y dice si va a escalar.
 *
 * Dos fuentes de diagnóstico:
 *
 *  1. La forma del SQL. Un `LIKE '%algo%'` no puede usar un índice en ningún
 *     motor, y eso se ve sin ejecutar nada.
 *  2. El plan de ejecución real (EXPLAIN), que sabe qué índice se va a usar y
 *     cuántas filas cree el motor que va a tocar.
 *
 * La idea es que puedas responder "¿esto aguanta 10 millones de filas?" antes
 * de tener 10 millones de filas.
 */
class QueryAnalyzer
{
    public const SEVERITY_CRITICAL = 'critical';

    public const SEVERITY_WARNING = 'warning';

    public const SEVERITY_INFO = 'info';

    /**
     * @param Builder<\Illuminate\Database\Eloquent\Model> $query
     * @return array<int, array{severity: string, code: string, message: string, hint: string}>
     */
    public function analyze(Builder $query): array
    {
        return array_merge(
            $this->analyzeSql($query),
            $this->analyzePlan($query),
        );
    }

    /* -----------------------------------------------------------------
     | Análisis estático del SQL
     | ----------------------------------------------------------------- */

    /**
     * @param Builder<\Illuminate\Database\Eloquent\Model> $query
     * @return array<int, array<string, string>>
     */
    protected function analyzeSql(Builder $query): array
    {
        $findings = [];
        $sql = $query->toSql();
        $bindings = $query->getBindings();

        // LIKE con comodin por delante: nunca usa indice, en ningun motor.
        $leading = 0;
        foreach ($bindings as $binding) {
            if (is_string($binding) && str_starts_with($binding, '%')) {
                $leading++;
            }
        }

        if ($leading > 0) {
            $findings[] = $this->finding(
                $leading > 2 ? self::SEVERITY_CRITICAL : self::SEVERITY_WARNING,
                'like.leading_wildcard',
                "{$leading} condicion(es) LIKE empiezan por '%', que impide usar el indice.",
                'Usa TextSearch::prefix() para autocompletados, fullText() para busqueda real, '
                . 'o delega en un motor externo con EngineFilter.'
            );
        }

        // Un OR entre columnas distintas suele hacer que el motor abandone los
        // indices y recurra a un full scan.
        if (preg_match_all('/\bor\s+"?[a-z_]+"?\."?[a-z_]+"?\s+(like|ilike)\s/i', $sql) > 0) {
            $findings[] = $this->finding(
                self::SEVERITY_WARNING,
                'like.or_across_columns',
                'Hay un OR de LIKE sobre varias columnas.',
                'El motor suele descartar los indices ante un OR entre columnas. '
                . 'Una columna generada que concatene las buscadas, con un solo indice, rinde mejor.'
            );
        }

        // Envolver la columna en una funcion la vuelve no sargable.
        // La columna no tiene por que ser el primer argumento ni venir
        // cualificada: SQLite genera strftime('%Y-%m-%d', "col") y MySQL
        // date("tabla"."col"). CAST queda fuera a proposito: Laravel lo aplica
        // del lado del valor, no de la columna.
        if (preg_match('/\b(date|strftime|lower|upper|coalesce|concat)\s*\([^)]*"[a-z_]+"(\."[a-z_]+")?\s*[,)]/i', $sql)) {
            $findings[] = $this->finding(
                self::SEVERITY_WARNING,
                'sql.function_on_column',
                'Alguna condicion envuelve una columna en una funcion.',
                'Eso impide usar su indice. Reescribelo como un rango sobre la columna desnuda, '
                . 'que es lo que hace DateFilterQuery.'
            );
        }

        if (! str_contains(strtolower($sql), 'order by')) {
            $findings[] = $this->finding(
                self::SEVERITY_INFO,
                'order.missing',
                'La consulta no tiene ORDER BY.',
                'Sin un orden total, dos paginas consecutivas pueden repetir o saltarse filas. '
                . 'Usa Order::fallback() en algun filtro.'
            );
        }

        return $findings;
    }

    /* -----------------------------------------------------------------
     | Plan de ejecución
     | ----------------------------------------------------------------- */

    /**
     * @param Builder<\Illuminate\Database\Eloquent\Model> $query
     * @return array<int, array<string, string>>
     */
    protected function analyzePlan(Builder $query): array
    {
        // Una consulta sin condiciones TIENE que recorrer la tabla: avisar de
        // eso solo genera ruido. El scan es un problema cuando hay un WHERE que
        // deberia haber podido usar un indice.
        $base = $query->getQuery();
        $constrained = ! empty($base->wheres) || ! empty($base->joins) || ! empty($base->groups);

        if (! $constrained) {
            return [];
        }

        try {
            $rows = $this->explain($query);
        } catch (\Throwable $e) {
            return [$this->finding(
                self::SEVERITY_INFO,
                'plan.unavailable',
                'No se pudo obtener el plan de ejecucion: ' . $e->getMessage(),
                'El analisis estatico del SQL sigue siendo valido.'
            )];
        }

        return match ($query->getConnection()->getDriverName()) {
            'mysql', 'mariadb' => $this->analyzeMysqlPlan($rows),
            'pgsql' => $this->analyzePostgresPlan($rows),
            'sqlite' => $this->analyzeSqlitePlan($rows),
            default => [],
        };
    }

    /**
     * @param Builder<\Illuminate\Database\Eloquent\Model> $query
     * @return array<int, array<string, mixed>>
     */
    public function explain(Builder $query): array
    {
        $connection = $query->getConnection();
        $sql = $query->toSql();
        $bindings = $query->getBindings();

        $statement = match ($connection->getDriverName()) {
            'sqlite' => 'EXPLAIN QUERY PLAN ',
            'pgsql' => 'EXPLAIN (FORMAT JSON) ',
            default => 'EXPLAIN ',
        };

        return array_map(
            static fn ($row): array => (array) $row,
            $connection->select($statement . $sql, $bindings)
        );
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @return array<int, array<string, string>>
     */
    protected function analyzeMysqlPlan(array $rows): array
    {
        $findings = [];

        foreach ($rows as $row) {
            $table = (string) ($row['table'] ?? '?');
            $type = strtolower((string) ($row['type'] ?? ''));
            $key = $row['key'] ?? null;
            $examined = (int) ($row['rows'] ?? 0);
            $extra = strtolower((string) ($row['Extra'] ?? $row['extra'] ?? ''));

            if ($type === 'all') {
                $findings[] = $this->finding(
                    self::SEVERITY_CRITICAL,
                    'plan.full_table_scan',
                    "[{$table}] full table scan: examina ~" . number_format($examined) . ' filas.',
                    'Falta un indice util para este WHERE, o la condicion no es sargable.'
                );
            } elseif ($type === 'index') {
                $findings[] = $this->finding(
                    self::SEVERITY_WARNING,
                    'plan.full_index_scan',
                    "[{$table}] recorre el indice entero: ~" . number_format($examined) . ' filas.',
                    'Mejor que un full scan, pero el coste sigue creciendo con la tabla.'
                );
            }

            if ($key === null && $type !== 'all') {
                $findings[] = $this->finding(
                    self::SEVERITY_WARNING,
                    'plan.no_index',
                    "[{$table}] no usa ningun indice.",
                    'Revisa las columnas por las que filtras y ordenas.'
                );
            }

            if (str_contains($extra, 'using filesort')) {
                $findings[] = $this->finding(
                    self::SEVERITY_WARNING,
                    'plan.filesort',
                    "[{$table}] ordena en memoria o en disco (filesort).",
                    'Un indice que cubra el ORDER BY evita la ordenacion.'
                );
            }

            if (str_contains($extra, 'using temporary')) {
                $findings[] = $this->finding(
                    self::SEVERITY_WARNING,
                    'plan.temporary_table',
                    "[{$table}] necesita una tabla temporal.",
                    'Suele venir de un GROUP BY u ORDER BY que el indice no cubre.'
                );
            }
        }

        return $findings;
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @return array<int, array<string, string>>
     */
    protected function analyzePostgresPlan(array $rows): array
    {
        $json = $rows[0]['QUERY PLAN'] ?? $rows[0]['query plan'] ?? null;

        if (! is_string($json)) {
            return [];
        }

        $findings = [];
        $plan = json_decode($json, true);
        $text = strtolower($json);

        if (str_contains($text, '"seq scan"')) {
            $findings[] = $this->finding(
                self::SEVERITY_CRITICAL,
                'plan.seq_scan',
                'El plan incluye un Seq Scan (recorrido secuencial de la tabla).',
                'Falta un indice util, o la condicion no es sargable.'
            );
        }

        if (str_contains($text, '"sort"') && ! str_contains($text, 'index scan')) {
            $findings[] = $this->finding(
                self::SEVERITY_WARNING,
                'plan.sort',
                'El plan ordena explicitamente en vez de leer en orden del indice.',
                'Un indice que cubra el ORDER BY evita la ordenacion.'
            );
        }

        $cost = $plan[0]['Plan']['Total Cost'] ?? null;

        if (is_numeric($cost) && $cost > 10000) {
            $findings[] = $this->finding(
                self::SEVERITY_WARNING,
                'plan.high_cost',
                'Coste estimado alto: ' . number_format((float) $cost, 2) . '.',
                'Revisa los indices de las columnas del WHERE y del ORDER BY.'
            );
        }

        return $findings;
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @return array<int, array<string, string>>
     */
    protected function analyzeSqlitePlan(array $rows): array
    {
        $findings = [];

        foreach ($rows as $row) {
            $detail = (string) ($row['detail'] ?? '');

            if (str_starts_with($detail, 'SCAN')) {
                $findings[] = $this->finding(
                    str_contains($detail, 'USING') ? self::SEVERITY_WARNING : self::SEVERITY_CRITICAL,
                    'plan.scan',
                    $detail,
                    'SCAN recorre toda la tabla o todo el indice; SEARCH usa el indice para saltar.'
                );
            }

            if (str_contains($detail, 'USE TEMP B-TREE')) {
                $findings[] = $this->finding(
                    self::SEVERITY_WARNING,
                    'plan.temp_btree',
                    $detail,
                    'Ordena construyendo un arbol temporal; un indice sobre el ORDER BY lo evita.'
                );
            }
        }

        return $findings;
    }

    /* -----------------------------------------------------------------
     | Utilidades
     | ----------------------------------------------------------------- */

    /**
     * @return array{severity: string, code: string, message: string, hint: string}
     */
    protected function finding(string $severity, string $code, string $message, string $hint): array
    {
        return compact('severity', 'code', 'message', 'hint');
    }

    /**
     * El SQL con los bindings interpolados, solo para mostrarlo.
     *
     * @param Builder<\Illuminate\Database\Eloquent\Model> $query
     */
    public static function toRawSql(Builder $query): string
    {
        $sql = $query->toSql();

        foreach ($query->getBindings() as $binding) {
            $value = match (true) {
                is_null($binding) => 'null',
                is_bool($binding) => $binding ? '1' : '0',
                is_numeric($binding) => (string) $binding,
                $binding instanceof \DateTimeInterface => "'" . $binding->format('Y-m-d H:i:s') . "'",
                default => "'" . str_replace("'", "''", (string) $binding) . "'",
            };

            $sql = preg_replace('/\?/', str_replace('$', '\\$', $value), $sql, 1) ?? $sql;
        }

        return $sql;
    }
}
