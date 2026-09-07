<?php

namespace Innoboxrr\SearchSurge\Search\Contracts;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Innoboxrr\SearchSurge\Search\Support\DataContainer;

/**
 * Contrato de un filtro de SearchSurge.
 *
 * Implementarlo es opcional: cualquier clase con un método estático apply()
 * sigue funcionando igual. Está aquí para que el IDE y el análisis estático
 * conozcan la firma.
 *
 * Un filtro puede además declarar dos propiedades estáticas opcionales que
 * SearchSurge lee por reflexión:
 *
 *   public static array $keys = ['id', 'ids', 'orderBy'];
 *       Las claves de entrada que le interesan. Si ninguna viene en los datos,
 *       el filtro no se instancia ni se ejecuta. Declárala completa: si el
 *       filtro también ordena, incluye 'orderBy'.
 *
 *   public static int $priority = 0;
 *       Orden de aplicación, de menor a mayor. Los filtros de autorización
 *       conviene que corran primero.
 */
interface Filter
{
    /**
     * Aplica el filtro a la consulta.
     *
     * Devolver el builder es lo recomendado, pero SearchSurge también acepta
     * que lo mutes sin devolverlo.
     *
     * @param Builder<Model> $query
     * @return Builder<Model>|void
     */
    public static function apply(Builder $query, DataContainer $data);
}
