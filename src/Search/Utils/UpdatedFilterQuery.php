<?php 

namespace Desar\SearchSurge\Search\Utils;

use Illuminate\Database\Eloquent\Builder;
use Carbon\Carbon;

class UpdatedFilterQuery
{

	public static function sort(Builder $query, $request)
	{

		if ($request->updated_at) {

            $date = Carbon::parse($request->updated_at);

            if($request->operator == '>') {

                $query->whereDate('updated_at', '>', $date);

            }

            if($request->operator == '>=') {

                $query->whereDate('updated_at', '>=', $date);

            }
        
            if($request->operator == '<') {

                $query->whereDate('updated_at', '<', $date);

            }

            if($request->operator == '<=') {

                $query->whereDate('updated_at', '<=', $date);

            }

            if($request->operator == '==') {

                $query->whereDate('updated_at', '=', $date);

            }

            if($request->operator == '!=') {

                $query->whereDate('updated_at', '!=', $date);

            }

        }

        if($request->updated_at_start_date && $request->updated_at_end_date) {

            $updated_at_start_date = Carbon::parse($request->updated_at_start_date);

            $updated_at_end_date = Carbon::parse($request->updated_at_end_date);

            $query->whereDate('updated_at', '>=', $updated_at_start_date)->whereDate('updated_at', '<=', $updated_at_end_date);

        }

        return $query;

	}

}