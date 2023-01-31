<?php 

namespace Desar\SearchSurge\Search\Utils;

use Illuminate\Database\Eloquent\Builder;

class Managed
{

	public static function apply(Builder $query, $request)
    {  

        // 1. Primero debemos ver si se requiere las entidades administrables por el usuario
        if (auth()->check() && $request->managed == true) {

            // Depués debemos verificar si se requiere hacer una excepción para los que puedan ver todas
            if($request->except_view_any == true) {

                // Si el usuario no puede ver todas, limitar a las que puede administrar
                if(auth()->user()->cant('viewAny', $request->model)) {

                    $query = self::canViewConstraint($query, auth()->user(), $request);

                }

            // En caso de que no se requiera hacer la excepción de todas formas hacer la restricción
            } else {

                $query = self::canViewConstraint($query, auth()->user(), $request);

            }

        }

        return $query;

    }

    public static function canViewConstraint($query, $user, $request) 
    {

        $filter = $request->managedFilterClass;

        if(class_exists($filter)) {

            $query = $filter::canView($query, auth()->user(), $request->all());

        }

        return $query;

    }

}