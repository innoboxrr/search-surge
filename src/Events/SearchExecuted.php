<?php

namespace Innoboxrr\SearchSurge\Events;

/**
 * Se emite cada vez que una búsqueda se ejecuta contra la base de datos.
 *
 * Sirve para métricas y para encontrar el listado que se está degradando antes
 * de que alguien se queje. La información que trae es la que hace falta para
 * reproducirlo: qué modelo, qué filtros entraron, qué SQL salió y cuánto tardó.
 */
class SearchExecuted
{
    /**
     * @param class-string $model
     * @param array<int, class-string> $filters Los que realmente se aplicaron.
     * @param array<int, string> $parameters Claves de la petición, sin valores.
     * @param array<int, mixed> $bindings
     */
    public function __construct(
        public readonly string $model,
        public readonly array $filters,
        public readonly array $parameters,
        public readonly string $sql,
        public readonly array $bindings,
        public readonly float $milliseconds,
        public readonly ?int $results = null,
    ) {}

    /**
     * ¿Ha tardado más de lo aceptable?
     */
    public function isSlow(float $threshold): bool
    {
        return $this->milliseconds >= $threshold;
    }

    /**
     * Resumen apto para un log o una métrica.
     *
     * Los valores de los parámetros no van: pueden ser datos personales y esto
     * acaba en ficheros de log que se guardan mucho tiempo.
     *
     * @return array<string, mixed>
     */
    public function context(): array
    {
        return [
            'model' => $this->model,
            'ms' => round($this->milliseconds, 2),
            'results' => $this->results,
            'filters' => array_map('class_basename', $this->filters),
            'parameters' => $this->parameters,
            'sql' => $this->sql,
        ];
    }
}
