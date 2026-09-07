<?php

namespace Innoboxrr\SearchSurge\Tests\Fixtures\Engine;

/**
 * Motor de busqueda falso. Sustituye a Elastic/Algolia en los tests: lo unico
 * que importa del contrato es que devuelve ids en orden de relevancia.
 */
class FakeEngine
{
    /** @var array<int, int|string> */
    public static array $ids = [];

    /** @var array<int, string> Terminos que ha recibido. */
    public static array $queries = [];

    public static int $lastLimit = 0;

    /** @return array<int, int|string> */
    public static function search(string $term, int $limit): array
    {
        self::$queries[] = $term;
        self::$lastLimit = $limit;

        return array_slice(self::$ids, 0, $limit);
    }

    public static function reset(): void
    {
        self::$ids = [];
        self::$queries = [];
        self::$lastLimit = 0;
    }
}
