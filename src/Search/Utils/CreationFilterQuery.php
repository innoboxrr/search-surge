<?php 

namespace Innoboxrr\SearchSurge\Search\Utils;

use Illuminate\Database\Eloquent\Builder;
use Carbon\Carbon;

class CreationFilterQuery
{

	public static function sort(Builder $query, $data)
	{

		if ($data->created_at) {

            $date = Carbon::parse($data->created_at);

            if($data->operator == '>') {

                $query->whereDate('created_at', '>', $date);

            }

            if($data->operator == '>=') {

                $query->whereDate('created_at', '>=', $date);

            }
        
            if($data->operator == '<') {

                $query->whereDate('created_at', '<', $date);

            }

            if($data->operator == '<=') {

                $query->whereDate('created_at', '<=', $date);

            }

            if($data->operator == '==') {

                $query->whereDate('created_at', '=', $date);

            }

            if($data->operator == '!=') {

                $query->whereDate('created_at', '!=', $date);

            }

        }

        if($data->created_at_start_date && $data->created_at_end_date) {

            $created_at_start_date = Carbon::parse($data->created_at_start_date)->startOfDay();

            $created_at_end_date = Carbon::parse($data->created_at_end_date)->endOfDay();

            $query->whereBetween('created_at', [
                $created_at_start_date,
                $created_at_end_date
            ]);

        }

        return $query;

	}

}