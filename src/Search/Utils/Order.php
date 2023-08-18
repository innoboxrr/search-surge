<?php

namespace Innoboxrr\SearchSurge\Search\Utils;

use Illuminate\Database\Eloquent\Builder;

Class Order
{

	/* Request must have:
	 * orderBy = column name
	 * orderMode = 'asc' | ' desc'
	 */

    public static function orderBy(Builder $query, $data, $column)
    {

        if ($data->has('orderBy') && $data->orderBy == $column) {

            $orderMode = ($data->orderMode == 'desc') ? 'desc' : 'asc';

            $query->orderBy($column, $orderMode);

        }

        return $query;

    }

}
