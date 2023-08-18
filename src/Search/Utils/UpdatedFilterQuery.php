<?php 

namespace Innoboxrr\SearchSurge\Search\Utils;

use Illuminate\Database\Eloquent\Builder;
use Carbon\Carbon;

class UpdatedFilterQuery
{

	public static function sort(Builder $query, $data)
	{

		if ($data->updated_at) {

            $date = Carbon::parse($data->updated_at);

            if($data->operator == '>') {

                $query->whereDate('updated_at', '>', $date);

            }

            if($data->operator == '>=') {

                $query->whereDate('updated_at', '>=', $date);

            }
        
            if($data->operator == '<') {

                $query->whereDate('updated_at', '<', $date);

            }

            if($data->operator == '<=') {

                $query->whereDate('updated_at', '<=', $date);

            }

            if($data->operator == '==') {

                $query->whereDate('updated_at', '=', $date);

            }

            if($data->operator == '!=') {

                $query->whereDate('updated_at', '!=', $date);

            }

        }

        if($data->updated_at_start_date && $data->updated_at_end_date) {

            $updated_at_start_date = Carbon::parse($data->updated_at_start_date);

            $updated_at_end_date = Carbon::parse($data->updated_at_end_date);

            $query->whereDate('updated_at', '>=', $updated_at_start_date)->whereDate('updated_at', '<=', $updated_at_end_date);

        }

        return $query;

	}

}