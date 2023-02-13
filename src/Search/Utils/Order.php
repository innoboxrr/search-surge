<?php

namespace Innoboxrr\SearchSurge\Search\Utils;

use Illuminate\Database\Eloquent\Builder;

Class Order
{

	/* Request must have:
	 * orderBy = column name
	 * orderMode = 'asc' | ' desc'
	 */

    public static function orderBy(Builder $query, $request, $column)
    {

        if ($request->has('orderBy') && $request->orderBy == $column) {

            $orderMode = ($request->orderMode == 'desc') ? 'desc' : 'asc';

            $query->orderBy($column, $orderMode);

        }

        return $query;

    }

}
