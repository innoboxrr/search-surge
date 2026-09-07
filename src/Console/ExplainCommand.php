<?php

namespace Innoboxrr\SearchSurge\Console;

use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;
use Innoboxrr\SearchSurge\Search\Builder;
use Innoboxrr\SearchSurge\Search\Support\FilterMeta;
use Innoboxrr\SearchSurge\Search\Support\FilterRegistry;
use Innoboxrr\SearchSurge\Search\Support\QueryAnalyzer;

/**
 * Responde "¿esta búsqueda aguanta 10 millones de filas?" antes de tenerlos.
 *
 *   php artisan search-surge:explain "App\Models\Deal"
 *   php artisan search-surge:explain "App\Models\Deal" --data='{"global":"zapato"}'
 *   php artisan search-surge:explain "App\Models\Deal" --data='{"orderBy":"created_at"}' --time
 */
class ExplainCommand extends Command
{
    protected $signature = 'search-surge:explain
                            {model : Clase del modelo}
                            {--data= : Datos de la busqueda en JSON}
                            {--count : Analizar tambien el COUNT(*) del paginador}
                            {--time : Ejecutar la consulta y medir cuanto tarda}
                            {--sql : Mostrar solo el SQL}';

    protected $description = 'Analiza la consulta de una busqueda y avisa de lo que no va a escalar';

    public function handle(Builder $builder, FilterRegistry $registry, QueryAnalyzer $analyzer): int
    {
        $model = ltrim((string) $this->argument('model'), '\\');

        if (! class_exists($model)) {
            $this->components->error("La clase [{$model}] no existe.");

            return self::FAILURE;
        }

        $data = $this->data();

        if ($data === null) {
            return self::FAILURE;
        }

        $query = $builder->query($model, $data);

        if ($this->option('sql')) {
            $this->line(QueryAnalyzer::toRawSql($query));

            return self::SUCCESS;
        }

        $this->renderFilters($registry, $model, $data);
        $this->renderSql($query);
        $this->renderPlan($analyzer, $query);

        if ($this->option('time')) {
            $this->renderTiming($query);
        }

        $findings = $analyzer->analyze($query);

        if ($this->option('count')) {
            $findings = array_merge($findings, $this->analyzeCount($analyzer, $query));
        }

        return $this->renderFindings($findings);
    }

    /* -----------------------------------------------------------------
     | Secciones
     | ----------------------------------------------------------------- */

    /**
     * @param array<string, mixed> $data
     */
    protected function renderFilters(FilterRegistry $registry, string $model, array $data): void
    {
        $filters = $registry->resolve($model);

        $this->newLine();
        $this->components->twoColumnDetail('<fg=cyan;options=bold>Modelo</>', $model);
        $this->components->twoColumnDetail('Filtros resueltos', (string) count($filters));

        foreach ($filters as $filter) {
            $keys = FilterMeta::keys($filter);
            $applied = $keys === null || array_intersect($keys, array_keys($data)) !== [];

            $this->components->twoColumnDetail(
                '  '.class_basename($filter),
                $applied ? '<fg=green>aplicado</>' : '<fg=gray>omitido (no vienen sus claves)</>'
            );
        }
    }

    /**
     * @param \Illuminate\Database\Eloquent\Builder<Model> $query
     */
    protected function renderSql($query): void
    {
        $this->newLine();
        $this->line('<fg=cyan;options=bold>  SQL</>');
        $this->line('  '.QueryAnalyzer::toRawSql($query));
    }

    /**
     * @param \Illuminate\Database\Eloquent\Builder<Model> $query
     */
    protected function renderPlan(QueryAnalyzer $analyzer, $query): void
    {
        $this->newLine();
        $this->line('<fg=cyan;options=bold>  Plan de ejecucion</>');

        try {
            $rows = $analyzer->explain($query);
        } catch (\Throwable $e) {
            $this->line('  <fg=gray>no disponible: '.$e->getMessage().'</>');

            return;
        }

        if ($rows === []) {
            $this->line('  <fg=gray>vacio</>');

            return;
        }

        $columns = array_keys($rows[0]);

        $this->table(
            $columns,
            array_map(
                static fn (array $row): array => array_map(
                    static fn ($v): string => (string) (is_scalar($v) || $v === null ? $v : json_encode($v)),
                    $row
                ),
                $rows
            )
        );
    }

    /**
     * @param \Illuminate\Database\Eloquent\Builder<Model> $query
     */
    protected function renderTiming($query): void
    {
        $this->newLine();
        $this->line('<fg=cyan;options=bold>  Medicion real</>');

        $start = microtime(true);
        $rows = $query->limit(50)->get();
        $elapsed = (microtime(true) - $start) * 1000;

        $this->components->twoColumnDetail('  Primeras 50 filas', sprintf('%.1f ms (%d devueltas)', $elapsed, $rows->count()));

        $start = microtime(true);
        $total = $query->toBase()->getCountForPagination();
        $countElapsed = (microtime(true) - $start) * 1000;

        $this->components->twoColumnDetail('  COUNT(*) del paginador', sprintf('%.1f ms (%s filas)', $countElapsed, number_format($total)));

        if ($countElapsed > $elapsed * 3 && $countElapsed > 50) {
            $this->components->twoColumnDetail(
                '  <fg=yellow>Aviso</>',
                'El conteo cuesta mucho mas que los datos. Considera count_cache o el paginador simple/cursor.'
            );
        }
    }

    /**
     * @param \Illuminate\Database\Eloquent\Builder<Model> $query
     * @return array<int, array<string, string>>
     */
    protected function analyzeCount(QueryAnalyzer $analyzer, $query): array
    {
        $countQuery = (clone $query)->getQuery()->cloneWithout(['columns', 'orders', 'limit', 'offset']);

        $this->newLine();
        $this->line('<fg=cyan;options=bold>  COUNT(*) del paginador</>');
        $this->line('  '.$countQuery->toSql());

        return [];
    }

    /**
     * @param array<int, array<string, string>> $findings
     */
    protected function renderFindings(array $findings): int
    {
        $this->newLine();
        $this->line('<fg=cyan;options=bold>  Diagnostico</>');

        if ($findings === []) {
            $this->components->twoColumnDetail('  <fg=green>Sin avisos</>', 'la consulta deberia escalar bien');
            $this->newLine();

            return self::SUCCESS;
        }

        $order = [
            QueryAnalyzer::SEVERITY_CRITICAL => 0,
            QueryAnalyzer::SEVERITY_WARNING => 1,
            QueryAnalyzer::SEVERITY_INFO => 2,
        ];

        usort($findings, static fn (array $a, array $b): int => ($order[$a['severity']] ?? 3) <=> ($order[$b['severity']] ?? 3));

        $critical = 0;

        foreach ($findings as $finding) {
            [$color, $label] = match ($finding['severity']) {
                QueryAnalyzer::SEVERITY_CRITICAL => ['red', 'CRITICO'],
                QueryAnalyzer::SEVERITY_WARNING => ['yellow', 'AVISO  '],
                default => ['gray', 'INFO   '],
            };

            if ($finding['severity'] === QueryAnalyzer::SEVERITY_CRITICAL) {
                $critical++;
            }

            $this->newLine();
            $this->line("  <fg={$color};options=bold>{$label}</>  {$finding['message']}");
            $this->line("           <fg=gray>{$finding['hint']}</>");
        }

        $this->newLine();

        // Solo lo critico rompe el comando, para poder usarlo en CI.
        return $critical > 0 ? self::FAILURE : self::SUCCESS;
    }

    /* -----------------------------------------------------------------
     | Entrada
     | ----------------------------------------------------------------- */

    /**
     * @return array<string, mixed>|null
     */
    protected function data(): ?array
    {
        $raw = $this->option('data');

        if ($raw === null || $raw === '') {
            return [];
        }

        $decoded = json_decode((string) $raw, true);

        if (! is_array($decoded)) {
            $this->components->error('--data debe ser un objeto JSON valido. '.json_last_error_msg());

            return null;
        }

        return $decoded;
    }
}
