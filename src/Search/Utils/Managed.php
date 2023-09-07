<?php 

namespace Innoboxrr\SearchSurge\Search\Utils;

use Illuminate\Database\Eloquent\Builder;

class Managed
{

	public static function apply(Builder $query, $data)
    {  

        // 1. Primero debemos ver si se requiere las entidades administrables por el usuario
        if (auth()->check() && $data->managed == true) {

            // Depués debemos verificar si se requiere hacer una excepción para los que puedan ver todas
            if($data->except_view_any == true) {

                // Si el usuario no puede ver todas, limitar a las que puede administrar
                if(auth()->user()->cant('viewAny', get_class($data->modelClass))) {

                    $query = self::canViewConstraint($query, auth()->user(), $data);

                }

            // En caso de que no se requiera hacer la excepción de todas formas hacer la restricción
            } else {

                $query = self::canViewConstraint($query, auth()->user(), $data);

            }

        }

        return $query;

    }

    public static function canViewConstraint($query, $user, $data) 
    {

        $filter = $data->managedFilterClass;

        if(class_exists($filter)) {

            $query = $filter::canView($query, auth()->user(), $data->all());

        }

        return $query;

    }

}