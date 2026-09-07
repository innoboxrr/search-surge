<?php

use Innoboxrr\SearchSurge\Search\Filters\Common\IdFilter;
use Innoboxrr\SearchSurge\Search\Filters\Common\SoftDeletesFilter;
use Innoboxrr\SearchSurge\Search\Filters\Common\TimestampsFilter;

return [

    /*
    |--------------------------------------------------------------------------
    | Resolución de filtros
    |--------------------------------------------------------------------------
    |
    | SearchSurge resuelve los filtros de un modelo en este orden, y se queda
    | con la primera fuente que responda:
    |
    |   1. $options['filters']            -> lista explícita (la más rápida)
    |   2. FilterRegistry::register()     -> registro en un ServiceProvider
    |   3. Manifiesto compilado           -> php artisan search-surge:cache
    |   4. Model::surgeFilters()          -> declarado en el propio modelo
    |   5. $options['filtersNamespace']   -> namespace explícito
    |   6. 'namespaces' / convención      -> ver abajo
    |   7. $options['filtersPath']        -> ruta física (modo legado)
    |
    */

    'filters' => [

        /*
        | Mapa explícito modelo => filtros. Es la opción más eficiente porque
        | evita por completo tocar el sistema de archivos.
        |
        |   \App\Models\User::class => [
        |       \App\Models\Filters\User\IdFilter::class,
        |   ],
        */
        'map' => [],

        /*
        | Mapa de namespace de modelos => namespace de filtros. Útil cuando un
        | paquete no sigue la convención por defecto.
        |
        |   'Innoboxrr\\LaravelBlog\\Models' => 'Innoboxrr\\LaravelBlog\\Models\\Filters',
        */
        'namespaces' => [],

        /*
        | Convención por defecto. Con 'Filters', el modelo
        | App\Models\User busca sus filtros en App\Models\Filters\User\*.
        |
        | El directorio real se deduce del autoloader PSR-4 de Composer, así que
        | funciona igual si el paquete está en vendor/ o enlazado como path repo.
        */
        'suffix' => 'Filters',

        /*
        | Filtros genéricos que se añaden a TODOS los modelos, para que uno
        | nuevo responda a ?id=, ?ids=, ?created_at_start_date= y ?trashed= sin
        | crear un solo archivo.
        |
        | Cada uno se descarta si el modelo ya tiene algo equivalente: mismo
        | nombre corto de clase, o claves declaradas que se solapan. Un modelo
        | con su propio IdFilter no recibe el comun.
        |
        | Ponlo a [] para desactivarlos del todo.
        */
        'defaults' => [
            IdFilter::class,
            TimestampsFilter::class,
            SoftDeletesFilter::class,
        ],

        /*
        | Último recurso, para apps que no siguen ninguna convención.
        */
        'namespace' => 'App\\Models\\Filters',
        'path' => 'app'.DIRECTORY_SEPARATOR.'Models'.DIRECTORY_SEPARATOR.'Filters',

    ],

    /*
    |--------------------------------------------------------------------------
    | Caché del descubrimiento de filtros
    |--------------------------------------------------------------------------
    |
    | El escaneo de directorios se memoiza siempre en memoria durante el
    | request. Esta caché persiste además el resultado entre requests.
    |
    | 'enabled' => null significa "solo en producción", que es lo que quieres:
    | en local ves un filtro nuevo sin limpiar nada.
    |
    */

    'cache' => [
        'enabled' => env('SEARCH_SURGE_CACHE', null),
        'store' => env('SEARCH_SURGE_CACHE_STORE'),
        'ttl' => 86400,
        'prefix' => 'search-surge:filters:',
    ],

    /*
    |--------------------------------------------------------------------------
    | Paginación
    |--------------------------------------------------------------------------
    |
    | 'max_per_page' acota lo que llega por request para que nadie tumbe la base
    | pidiendo ?paginate=999999. Ponlo en null para desactivar el límite.
    |
    | 'paginator' acepta 'length_aware' (por defecto), 'simple' o 'cursor'.
    | 'simple' y 'cursor' se saltan el COUNT(*) y son mucho más baratos en
    | tablas grandes; 'cursor' además pagina por keyset en vez de OFFSET.
    |
    */

    'pagination' => [
        'per_page' => 10,
        'max_per_page' => 1000,
        'page_name' => 'page',
        'paginator' => 'length_aware',

        /*
        | Segundos que se reutiliza el COUNT(*) del paginador para la misma
        | combinación de filtros. null lo desactiva.
        |
        | En una tabla de millones de filas el conteo suele costar más que la
        | página de datos —la página son 20 filas por índice, el conteo recorre
        | todas las que cumplen el filtro— y se repite en cada carga. A cambio,
        | el total puede ir unos segundos desfasado.
        */
        'count_cache' => env('SEARCH_SURGE_COUNT_CACHE'),

        /*
        | Página máxima que se acepta. null lo desactiva.
        |
        | OFFSET obliga al motor a recorrer y descartar todo lo anterior, así
        | que el coste crece con la profundidad. Por encima del tope se lanza
        | PageLimitExceededException, que se renderiza como 400.
        */
        'max_page' => env('SEARCH_SURGE_MAX_PAGE'),

        /*
        | Añade la clave primaria como último criterio de desempate cuando la
        | consulta ya tiene un ORDER BY.
        |
        | Ordenar por una columna no única no define un orden total, y con
        | LIMIT/OFFSET eso hace que la página 2 pueda repetir una fila de la
        | página 1 y saltarse otra. Solo actúa si ya había orden.
        */
        'stable_order' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Búsqueda de texto
    |--------------------------------------------------------------------------
    |
    | Valores por defecto de Utils\TextSearch.
    |
    | 'min_length' evita que un `?q=a` dispare una búsqueda que va a devolver
    | media tabla. Súbelo si usas contains(), que no puede usar índices.
    |
    | 'max_terms' acota cuántas palabras del término se traducen a condiciones:
    | sin tope, pegar un párrafo en la caja de búsqueda genera un WHERE con
    | cientos de grupos anidados.
    |
    */

    'text' => [
        'min_length' => 1,
        'max_terms' => 8,
    ],

    /*
    |--------------------------------------------------------------------------
    | Observabilidad
    |--------------------------------------------------------------------------
    |
    | 'events' emite Events\SearchExecuted en cada busqueda ejecutada, con el
    | modelo, los filtros que entraron, el SQL y el tiempo.
    |
    | 'slow_threshold' son milisegundos: por encima, la busqueda se registra
    | como aviso. Es lo que te avisa de que un listado se esta degradando antes
    | de que alguien se queje.
    |
    */

    'observability' => [
        'events' => true,
        'slow_threshold' => env('SEARCH_SURGE_SLOW_MS'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Modo estricto
    |--------------------------------------------------------------------------
    |
    | Por defecto, si un filtro revienta se registra en el log y la búsqueda
    | continúa. En estricto la excepción se propaga.
    |
    | Los filtros de autorización (los que extienden Utils\Managed) SIEMPRE
    | propagan su excepción, sin importar esta bandera: devolver resultados sin
    | filtrar porque el filtro de permisos falló es una fuga de datos.
    |
    */

    'strict' => env('SEARCH_SURGE_STRICT', false),

];
