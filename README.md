# SearchSurge

Filtrado, ordenamiento y búsqueda para modelos Eloquent.

Escribes filtros pequeños, uno por archivo. SearchSurge los encuentra, los
ordena y los aplica. Funciona igual dentro de una app que dentro de un paquete,
sin rutas a mano y sin configuración.

**Laravel 12 · 13** · PHP 8.2+ · MySQL · PostgreSQL · SQLite

490 tests · 95% de cobertura · PHPStan sin errores · nada se publica sin pasar por ahí

```bash
composer require innoboxrr/search-surge
```

No hay que registrar nada: el ServiceProvider se autodescubre.

---

## Índice

- [Uso en 30 segundos](#uso-en-30-segundos)
- [Cero archivos: los filtros comunes](#cero-archivos-los-filtros-comunes)
- [Dentro de un paquete](#dentro-de-un-paquete-de-laravel)
- [Las piezas para escribir filtros](#las-piezas-para-escribir-filtros)
- [La API](#la-api)
- [Búsqueda de texto](#búsqueda-de-texto)
- [Motores externos: Elastic, Algolia, Meilisearch](#motores-externos-elastic-algolia-meilisearch)
- [¿Esto aguanta 10 millones de filas?](#esto-aguanta-10-millones-de-filas)
- [Escala y rendimiento](#escala-y-rendimiento)
- [El contrato de entrada](#el-contrato-de-entrada)
- [Autorización](#autorización)
- [Observabilidad](#observabilidad)
- [Comandos](#comandos)
- [Configuración](#configuración)
- [Recetas](#recetas)
- [Cómo depurar cuando algo no filtra](#cómo-depurar-cuando-algo-no-filtra)
- [Contribuir](#contribuir)
- [Migrar de v2](#migrar-de-v2-a-v3)

---

## Uso en 30 segundos

Un modelo:

```php
namespace App\Models;

class User extends Model {}
```

Un filtro. Lo puedes generar:

```bash
php artisan search-surge:filter "App\Models\User" Name
```

```php
namespace App\Models\Filters\User;

use Illuminate\Database\Eloquent\Builder;
use Innoboxrr\SearchSurge\Search\Contracts\Filter;
use Innoboxrr\SearchSurge\Search\Support\DataContainer;
use Innoboxrr\SearchSurge\Search\Utils\Order;

class NameFilter implements Filter
{
    // Si no viene ninguna de estas claves, el filtro ni se ejecuta.
    public static array $keys = ['name', ...Order::KEYS];

    public static function apply(Builder $query, DataContainer $data)
    {
        if ($data->filled('name')) {
            $query->where('name', $data->string('name'));
        }

        return Order::orderBy($query, $data, 'name');
    }
}
```

Y la búsqueda:

```php
use Innoboxrr\SearchSurge\Facades\SearchSurge;

SearchSurge::get(User::class, $request->all());
```

Eso es todo. La convención `App\Models\User` → `App\Models\Filters\User\*` se
resuelve sola.

---

## Cero archivos: los filtros comunes

Un modelo nuevo ya responde a lo de siempre sin que crees nada:

```php
SearchSurge::get(Producto::class, $request->all());
```

```
?id=7                                ?ids=1,2,3            ?id_not=4
?created_at_start_date=2026-01-01    ?updated_at_operator=<
?orderBy=created_at&orderMode=desc   ?trashed=only
?paginate=25&page=2
```

Son tres filtros genéricos que leen la configuración del propio modelo: la clave
primaria de verdad (aunque se llame `codigo` y sea un uuid), los nombres reales
de las columnas de timestamps, y si usa `SoftDeletes`.

**No pisan a los tuyos.** Un común se descarta si el modelo ya tiene algo
equivalente, por dos reglas: mismo nombre corto de clase, o claves declaradas que
se solapan. Sin la segunda, un modelo con `CreationFilter` y `UpdatedFilter`
propios recibiría además el `TimestampsFilter` y las condiciones de fecha se
aplicarían dos veces.

Y no entran donde no los has pedido: si pasas `$options['filters']`, esa lista es
exhaustiva.

```php
'defaults' => [],   // desactivarlos del todo
```

---

## Dentro de un paquete de Laravel

Este era el punto doloroso de v2. Había que escribir la ruta física:

```php
// ❌ v2: se rompe con path repositories y al mover el paquete
'filtersPath' => 'vendor/innoboxrr/laravel-blog/src/Models/Filters',
```

Ahora hay tres formas, **todas sin rutas**.

### 1. Convención (cero configuración)

```
src/Models/Blog.php                     Innoboxrr\LaravelBlog\Models\Blog
src/Models/Filters/Blog/IdFilter.php    Innoboxrr\LaravelBlog\Models\Filters\Blog\IdFilter
```

```php
SearchSurge::get(Blog::class, $request->all());
```

SearchSurge deriva el namespace de los filtros del namespace del modelo y le
pregunta al autoloader de Composer dónde vive. Da igual si el paquete está en
`vendor/`, enlazado como *path repository* o movido de sitio.

### 2. Registro en tu ServiceProvider

```php
SearchSurge::registerNamespace(
    'Innoboxrr\LaravelBlog\Models',
    'Innoboxrr\LaravelBlog\Models\Filters'
);
```

### 3. Lista explícita — la más rápida

```php
SearchSurge::registerFilters(Blog::class, [IdFilter::class, CreationFilter::class]);
```

O desde el modelo, con el trait:

```php
class Blog extends Model
{
    use HasSearchSurge;

    public static function surgeFilters(): array
    {
        return [IdFilter::class, CreationFilter::class];
    }
}

Blog::surgeSearch($request->all());
Blog::surgeQuery($request->all());
Blog::surgeCount($request->all());
Blog::surgeLazy($request->all());
Blog::where('published', true)->surge($request->all())->paginate();
```

### Orden de resolución

Gana la primera fuente que responda:

| # | Fuente | Coste |
|---|--------|-------|
| 1 | `$options['filters']` | ninguno |
| 2 | `registerFilters()` / config `filters.map` | ninguno |
| 3 | Manifiesto compilado (`search-surge:cache`) | ninguno |
| 4 | `Model::surgeFilters()` | ninguno |
| 5 | `$options['filtersNamespace']` | autoloader |
| 6 | `registerNamespace()` / config `filters.namespaces` | autoloader |
| 7 | Convención | autoloader |
| 8 | `$options['filtersPath']` (legado) | disco |

```bash
php artisan search-surge:filters "App\Models\User"   # ¿cuáles se aplican y por qué?
```

---

## La API

```php
SearchSurge::get($model, $data, $options);      // Colección o paginador
SearchSurge::query($model, $data, $options);    // Builder de Eloquent, sin ejecutar
SearchSurge::count($model, $data, $options);    // int, sin traer filas
SearchSurge::exists($model, $data, $options);   // bool, se traduce a EXISTS
SearchSurge::first($model, $data, $options);    // Model|null, con LIMIT 1
SearchSurge::lazy($model, $data, $options);     // LazyCollection por lotes
SearchSurge::lazyById($model, $data, $options); // LazyCollection por keyset
SearchSurge::cursor($model, $data, $options);   // Cursor PDO, una sola consulta
```

### Datos (`$data`) — vienen de la petición

| Clave | Efecto |
|-------|--------|
| `paginate` | `0` trae todo; un número es el tamaño de página |
| `page` / `cursor` | Página o cursor actual |
| `orderBy` / `orderMode` | Columna y sentido (los interpreta cada filtro) |
| `paginator` | `length_aware` \| `simple` \| `cursor` |
| Lo que lean tus filtros | `name`, `id`, `created_at`, `q`… |

### Opciones (`$options`) — vienen de tu código

| Clave | Efecto |
|-------|--------|
| `filters` / `filtersNamespace` / `filtersPath` | De dónde salen los filtros |
| `query` | Builder de Eloquent del que partir |
| `columns` | Columnas a seleccionar en vez de `*` |
| `with` / `withCount` | Relaciones a precargar |
| `withoutGlobalScopes` | `true` o una lista |
| `perPage` / `maxPerPage` / `maxPage` | Tamaño y profundidad de página |
| `countCache` | Segundos que se reutiliza el `COUNT(*)` |
| `paginator` / `stableOrder` | Modo de paginación y desempate |
| `strict` | Si un filtro que falla debe propagar |
| `events` / `slowThreshold` | Observabilidad |

`columns` **solo** se lee de `$options`, nunca de `$data`: aceptar nombres de
columna desde la petición sería abrir la puerta a inyección.

---

## Las piezas para escribir filtros

Un filtro es tuyo: decides qué columnas expones y cómo. Estas utilidades cubren
las formas que se repiten, ya parametrizadas y con los casos raros resueltos.

```php
// Fechas: rangos y operadores, sin whereDate()
DateFilterQuery::apply($query, $data, 'created_at');
// ?created_at=2026-01-15&operator=>=   ?created_at_start_date=…&created_at_end_date=…

// Números: mismo vocabulario
NumericFilterQuery::apply($query, $data, 'precio');
// ?precio_min=10&precio_max=100   ?precio=50&precio_operator=>

// Conjuntos, con lista blanca
SetFilterQuery::apply($query, $data, 'estado', ['borrador', 'publicado']);
// ?estado=borrador,publicado   ?estado_not=archivado   ?estado=null

// Relaciones
RelationFilterQuery::exists($query, $data, 'comentarios');    // ?has_comentarios=1
RelationFilterQuery::count($query, $data, 'comentarios');     // ?comentarios_count_min=5
RelationFilterQuery::column($query, $data, 'autor', 'pais');  // ?autor_pais=MX

// Ordenamiento
Order::orderBy($query, $data, 'nombre');
Order::orderByAny($query, $data, ['nombre', 'created_at']);   // ?orderBy=nombre,-created_at
Order::fallback($query, $data, 'id', 'desc');
```

Cada una expone `keys()` para declarar el `$keys` del filtro sin escribirlas a
mano:

```php
public static array $keys = [...NumericFilterQuery::keys('precio'), ...Order::KEYS];
```

Dos decisiones de comportamiento que conviene conocer:

- **Un valor inválido no se ignora, no casa con nada.** `?precio=gratis` con
  operador no filtra (no se puede interpretar), pero `?estado=inventado` contra
  una lista blanca devuelve **cero filas**. Ignorar la condición devolvería la
  tabla entera, que es lo contrario de lo que se pidió.
- **Sin operador, un valor suelto no filtra.** `?precio=50` no dice si quieres
  "igual", "al menos" o "como mucho".

### Sobre `whereHas`

Existe la creencia de que `whereHas` es lento y hay que sustituirlo por un
`whereIn` con subconsulta. **Es falsa en MySQL 8.** Medido sobre 1.000.000 de
posts y 20.000 autores, "posts de autores de MX" (200.000 filas):

| | Contar | Traer una página |
|---|---|---|
| `whereHas` → EXISTS | 112,6 ms | 686 ms |
| `whereIn` subconsulta | 74,2 ms | 721 ms |
| `JOIN` | 90,1 ms | 682 ms |

Los tres producen **el mismo plan**: el optimizador los unifica. Por eso
`RelationFilterQuery` no trae ningún "selector inteligente de estrategia": sería
complejidad a cambio de nada.

Lo que sí cambia las cosas, por un factor de 27, es **no cruzar la relación
cuando no hace falta**:

| | Tiempo |
|---|---|
| `WHERE author_id = 7` | **0,3 ms** |
| `whereHas('author', id = 7)` | 8,2 ms |

Si la clave foránea está en tu tabla, un `SetFilterQuery` sobre ella basta. Usa
las relaciones para columnas que solo viven al otro lado.

---

## Búsqueda de texto

La diferencia entre las tres formas de buscar texto no es de estilo, es de tres
órdenes de magnitud. Medido en **MySQL 8 sobre 1.000.000 de filas**, con índice:

| | Tiempo | Plan |
|---|---|---|
| `TextSearch::prefix()` — `LIKE 'zapato%'` | **14,7 ms** | `range` sobre el índice |
| `TextSearch::fullText()` — `MATCH … AGAINST` | **356 ms** | índice `FULLTEXT` |
| `TextSearch::contains()` — `LIKE '%zapato%'` | **26.912 ms** | recorre el índice entero |
| `OR` de dos `contains()` en columnas distintas | **11.161 ms** | full table scan |

```php
use Innoboxrr\SearchSurge\Search\Utils\TextSearch;

// Autocompletado: la única variante de LIKE que usa índice.
TextSearch::prefix($query, $data, 'q', ['name']);

// Buscador real. Requiere índice FULLTEXT. MySQL y PostgreSQL.
TextSearch::fullText($query, $data, 'q', ['title', 'body'], ['mode' => 'boolean']);

// Solo si la tabla es pequeña y va a seguir siéndolo.
TextSearch::contains($query, $data, 'q', ['name'], ['minLength' => 3]);
```

Las tres:

- **Escapan los comodines.** Sin eso, `?q=%` significa "devuélvelo todo" y `?q=_`
  casa con cualquier carácter. Además de dar resultados absurdos, es una forma
  barata de tumbar la base. El escape es `~` con cláusula `ESCAPE` explícita,
  porque la barra invertida no es portable: MySQL obliga a `ESCAPE '\\'` y SQLite
  rechaza eso mismo.
- **Exigen todas las palabras** (`mode => 'any'` para lo contrario).
- **Acotan el número de términos** (8 por defecto), para que pegar un párrafo en
  la caja de búsqueda no genere un `WHERE` con cientos de grupos.
- **Usan `ILIKE` en PostgreSQL**, para que la sensibilidad a mayúsculas se
  comporte igual que en MySQL.

`fullText()` degrada a `contains()` donde el driver no lo soporta (SQLite, SQL
Server), para que tus tests no revienten. Con `['fallback' => 'throw']` lanza.

> Crear un índice `FULLTEXT` sobre 1M de filas tardó 7 minutos y bloqueó la
> tabla. Hazlo en ventana de mantenimiento.

---

## Motores externos: Elastic, Algolia, Meilisearch

Un motor de búsqueda y una base de datos no compiten: se reparten el trabajo.
El motor pone la relevancia, las erratas y los sinónimos; SQL pone los filtros
exactos, los joins y —lo importante— **la autorización**.

```
Elastic/Algolia  ->  [42, 17, 99, ...]   (relevancia)
         ↓
WHERE id IN (...) AND owner_id = ? ORDER BY FIELD(id, 42, 17, 99)
```

Eso es lo que hace que el híbrido gane a cualquiera de los dos por separado:
**los permisos nunca salen de la base de datos**, así que no hay que
desnormalizarlos al índice ni reindexar cuando cambian.

```bash
php artisan search-surge:filter "App\Models\Deal" Search --type=engine
```

```php
use Innoboxrr\SearchSurge\Search\Filters\EngineFilter;

class SearchFilter extends EngineFilter
{
    // Con Laravel Scout (Algolia, Meilisearch, Typesense, Elastic vía driver)
    // no hace falta nada más: el modelo usa el trait Searchable.
}
```

Con un cliente propio, sin Scout:

```php
class SearchFilter extends EngineFilter
{
    protected static function ids(Builder $query, DataContainer $data, string $term): array
    {
        return app(MiClienteElastic::class)->buscarIds($term, static::limit());
    }
}
```

Coste de la parte SQL, sobre 1M de filas:

| ids del motor | Tiempo |
|---|---|
| 20 | 0,6 ms |
| 200 | 1,6 ms |
| 1.000 | 5,8 ms |
| 5.000 | 34,2 ms |

Dos decisiones que hay que tomar a conciencia:

- **`limit()`** decide la forma del híbrido. Tope bajo: el motor pagina de hecho
  y SQL solo recorta, pero los totales pueden quedarse cortos si SQL descarta
  muchas filas. Tope alto: los totales son exactos y manda SQL, pero el
  `IN (...)` crece.
- **Si el motor está caído, la búsqueda falla.** `EngineFilter` se declara
  crítico a propósito: un `?q=zapato` que ignora el término y devuelve 1.000.000
  de filas es peor que un error, porque parece que funciona.

Y si el motor no encuentra nada, el resultado son **cero filas**, no todas.

---

## ¿Esto aguanta 10 millones de filas?

```bash
php artisan search-surge:explain "App\Models\Deal" --data='{"q":"zapato"}' --time
```

```
  Modelo ........................................ App\Models\Deal
  Filtros resueltos ........................................... 6
  ManagedFilter ....................................... aplicado
  CreationFilter ....................... omitido (no vienen sus claves)
  GlobalFilter ........................................ aplicado

  SQL
  select * from "deals" where "name" like '%zapato%' order by "created_at" desc

  Plan de ejecucion
  +----+--------+------------------------------+
  | id | parent | detail                       |
  +----+--------+------------------------------+
  | 3  | 0      | SCAN deals                   |
  | 17 | 0      | USE TEMP B-TREE FOR ORDER BY |
  +----+--------+------------------------------+

  Medicion real
  Primeras 50 filas ................................ 1240.3 ms
  COUNT(*) del paginador ........................... 8910.7 ms

  Diagnostico

  CRITICO  1 condicion(es) LIKE empiezan por '%', que impide usar el indice.
           Usa TextSearch::prefix() para autocompletados, fullText() para
           busqueda real, o delega en un motor externo con EngineFilter.

  AVISO    USE TEMP B-TREE FOR ORDER BY
           Ordena construyendo un arbol temporal; un indice sobre el ORDER BY
           lo evita.
```

Analiza dos cosas: **la forma del SQL** (un `LIKE '%…%'` se ve sin ejecutar nada)
y **el plan real** (`EXPLAIN` en MySQL, PostgreSQL y SQLite). Los hallazgos
críticos hacen que el comando salga con código 1, así que se puede poner en CI.

Un scan sin `WHERE` no se reporta: una consulta sin condiciones **tiene** que
recorrer la tabla, y avisar de eso solo devalúa los avisos de verdad.

---

## Escala y rendimiento

### Las fechas ya no hacen full scan

`whereDate()` envuelve la columna en una función y eso anula su índice. Cada
operador se traduce ahora a un rango semiabierto sobre la columna desnuda:

| Consulta (1M filas, MySQL) | v2 `whereDate` | v3 rango | |
|---|---|---|---|
| `created_at >= fecha` | 735 ms | **63 ms** | 11,6× |
| Rango de un mes | 924 ms | **3,8 ms** | **243×** |

Filas devueltas idénticas en los seis operadores.

### El `COUNT(*)` cuesta más que los datos

| | Tiempo |
|---|---|
| Página 1 (`LIMIT 20`) | 0,8 ms |
| `COUNT(*)` del paginador | 10,0 ms |
| `COUNT(*)` con un `LIKE '%…%'` | 179,3 ms |

Y se repite en cada carga. Tres salidas:

```php
'count_cache' => 30,        // reutiliza el total unos segundos
['paginator' => 'simple']   // sin total, sin COUNT
['paginator' => 'cursor']   // sin total y sin OFFSET
```

### `OFFSET` se degrada; el cursor no

| Página | OFFSET | Tiempo |
|---|---|---|
| 1 | 0 | 0,4 ms |
| 1.000 | 19.980 | 1,7 ms |
| 10.000 | 199.980 | **13,7 ms** |
| 25.000 | 499.980 | **33,0 ms** |
| cursor a la altura de la fila 900.000 | — | **0,2 ms** |

```php
'max_page' => 500,   // lanza PageLimitExceededException, que se renderiza 400
```

### Paginación estable

Ordenar por una columna no única no define un orden total: la página 2 puede
repetir una fila de la 1 y saltarse otra. SearchSurge añade la clave primaria
como desempate **si la consulta ya tiene un `ORDER BY`**. Si no ordenabas, no te
impone un orden (podría cambiar el plan).

### Los filtros que no aplican no se ejecutan

Declara `$keys` y SearchSurge se salta el filtro cuando ninguna viene con
contenido. Importa poco por el PHP que ahorras y mucho por los JOIN y
subconsultas que ya no se añaden.

### Manifiesto compilado

```bash
php artisan search-surge:cache     # en el deploy, junto a config:cache
```

La resolución pasa a ser una búsqueda en un array cargado desde OPcache: cero
disco, cero autoloader. Sin manifiesto, se memoiza en memoria durante el request
y solo se cachea entre requests **en producción**, para que en local veas un
filtro nuevo al instante.

### Exports

Un export que hace `->get()` sobre cientos de miles de filas las carga todas:

```php
return SearchSurge::lazy(Blog::class, $this->data);
```

---

## El contrato de entrada

La entrada dispersa —mandas solo lo que te interesa— tiene una consecuencia
incómoda: un `?nombre=x` en vez de `?name=x` no falla, simplemente no filtra.

Las `$keys` resuelven eso sin añadir nada nuevo: ya son la lista de parámetros
que cada filtro entiende.

```php
SearchSurge::schema(Deal::class);
// ['model' =>, 'filters' =>, 'parameters' =>, 'control' =>, 'complete' => bool]

SearchSurge::unknownParameters(Deal::class, $request->all());
// ['nombre']  ← nadie va a leer esto
```

```bash
php artisan search-surge:filters "App\Models\Deal" --json
```

Sirve para generar documentación, un esquema OpenAPI o los tipos del front-end
sin escribirlos a mano.

`complete` es `false` si algún filtro no declara `$keys`. En ese caso
`unknownParameters()` devuelve una lista vacía: no se puede afirmar que sobren, y
decir lo contrario sería mentir.

---

## Autorización

```php
class ManagedFilter extends Managed
{
    public static function canView($query, $user, array $args = [])
    {
        return $user->managedBlogFilter($query, $args);
    }
}
```

Se activa con `managed` en los datos; con `except_view_any`, quien tenga el
permiso `viewAny` se la salta.

Los descendientes de `Managed` son **filtros críticos**: se aplican primero y sus
excepciones se propagan en vez de tragarse. Devolver resultados sin acotar porque
el filtro de permisos falló sería una fuga de datos. Cualquier filtro puede
declararse crítico con `public static bool $critical = true`.

---

## Observabilidad

```php
Event::listen(SearchExecuted::class, function (SearchExecuted $e) {
    Metrics::timing('search.' . class_basename($e->model), $e->milliseconds);
});
```

El evento trae el modelo, **qué filtros entraron de verdad**, el SQL, los
bindings y el tiempo. Los *valores* de los parámetros no van en `context()`:
pueden ser datos personales y esto acaba en logs que se guardan mucho tiempo.

```php
'slow_threshold' => 500,   // ms; por encima se registra como aviso
```

Esto es lo que te avisa de que un listado se está degradando antes de que alguien
se queje. El log de consultas de Laravel no sirve para eso porque no sabe qué
filtros entraron.

---

## Comandos

```bash
php artisan search-surge:filter "App\Models\User" Name    # crear un filtro
php artisan search-surge:filter "App\Models\Deal" Search --type=engine
php artisan search-surge:filters "App\Models\User"        # ¿cuáles se aplican?
php artisan search-surge:filters --json                   # contrato de entrada
php artisan search-surge:explain "App\Models\Deal" --time # ¿va a escalar?
php artisan search-surge:cache                            # compilar manifiesto
php artisan search-surge:clear                            # borrarlo
```

`--type` acepta `basic`, `text`, `date`, `engine` y `managed`.

```bash
composer check     # estilo + analisis estatico + tests, lo mismo que CI
```

---

## Configuración

Solo si la necesitas:

```bash
php artisan vendor:publish --tag=search-surge-config
```

```php
'filters' => ['map' => [], 'namespaces' => [], 'suffix' => 'Filters'],
'cache' => ['enabled' => null, 'ttl' => 86400],   // null = solo en producción
'pagination' => [
    'per_page' => 10,
    'max_per_page' => 1000,
    'max_page' => null,
    'count_cache' => null,
    'paginator' => 'length_aware',
    'stable_order' => true,
],
'text' => ['min_length' => 1, 'max_terms' => 8],
'observability' => ['events' => true, 'slow_threshold' => null],
'strict' => false,
```

---

## Recetas

Casos completos, para copiar y adaptar.

### Un endpoint de listado, de principio a fin

```php
class IndexRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('viewAny', Deal::class);
    }

    public function handle()
    {
        return DealResource::collection(
            SearchSurge::get(Deal::class, $this->all())
        );
    }
}
```

```
GET /deals?status=activo,pausado&price_min=100&created_at_start_date=2026-01-01
          &orderBy=created_at&orderMode=desc&paginate=25&page=2
```

Sin escribir un filtro ya responde a `id`, `ids`, `id_not`, los rangos de fecha,
el orden y la paginación. Los filtros propios son para lo que es tuyo.

### Un autocompletado que no tumbe la base

```php
class NameFilter implements Filter
{
    public static array $keys = ['q'];

    public static function apply(Builder $query, DataContainer $data)
    {
        // prefix(), no contains(): 15 ms frente a 27 s sobre 1M de filas.
        return TextSearch::prefix($query, $data, 'q', ['name'], ['minLength' => 2]);
    }
}
```

```php
// El paginador simple no necesita el total, así que se ahorra el COUNT.
SearchSurge::get(Deal::class, $request->all(), ['paginator' => 'simple', 'perPage' => 10]);
```

### Un export que no se quede sin memoria

```php
class DealsExport implements FromView
{
    public function __construct(protected array $data) {}

    public function view(): View
    {
        return view('excel.deals', [
            // lazy() recorre por lotes: la RAM se mantiene plana aunque haya
            // medio millón de filas.
            'deals' => SearchSurge::lazy(Deal::class, $this->data),
        ]);
    }
}
```

### Un listado con millones de filas

```php
SearchSurge::get(Deal::class, $request->all(), [
    'paginator' => 'cursor',   // sin COUNT y sin OFFSET: coste plano
    'columns' => ['id', 'name', 'status', 'created_at'],
    'with' => ['advertiser:id,name'],
]);
```

Si necesitas el total, deja `length_aware` y añade `'countCache' => 30`.

### Buscar con Elasticsearch sin sacar los permisos de la base

```php
class SearchFilter extends EngineFilter
{
    protected static function limit(): int
    {
        return 500;
    }
}
```

El motor devuelve los ids por relevancia; SQL aplica los filtros exactos y el
`ManagedFilter`. Los permisos nunca salen de la base de datos.

### Filtrar por una relación

```php
class AdvertiserFilter implements Filter
{
    public static array $keys = [
        'advertiser_id',                                     // la vía rápida
        ...RelationFilterQuery::keys('advertiser', ['country']),
    ];

    public static function apply(Builder $query, DataContainer $data)
    {
        // Si la clave foránea está en tu tabla, úsala: 0,3 ms frente a 8,2 ms.
        SetFilterQuery::apply($query, $data, 'advertiser_id');

        // La relación, solo para columnas que viven al otro lado.
        return RelationFilterQuery::column($query, $data, 'advertiser', 'country');
    }
}
```

---

## Cómo depurar cuando algo no filtra

Por orden, de lo más probable a lo menos.

**1. ¿Se está aplicando tu filtro?**

```bash
php artisan search-surge:filters "App\Models\Deal"
```

Lista los filtros resueltos, en qué orden y con qué claves. Si el tuyo no
aparece, el problema es de descubrimiento: comprueba que está en
`App\Models\Filters\Deal\` y que la clase tiene un método estático `apply`.

**2. ¿Le llega el parámetro?**

```bash
php artisan search-surge:filters "App\Models\Deal" --json
```

Da la lista de parámetros que acepta el endpoint. Si mandas `?nombre=` y la lista
dice `name`, ahí está.

```php
SearchSurge::unknownParameters(Deal::class, $request->all());  // ['nombre']
```

**3. ¿El filtro se está saltando por `$keys`?**

Si declaras `$keys` y no incluyes todas las claves que lees, el filtro no se
ejecuta cuando llega solo la que olvidaste. El caso clásico es olvidar
`Order::KEYS` en un filtro que también ordena.

**4. ¿Qué SQL sale?**

```bash
php artisan search-surge:explain "App\Models\Deal" --data='{"q":"zapato"}' --sql
```

**5. ¿Por qué va lento?**

```bash
php artisan search-surge:explain "App\Models\Deal" --data='{"q":"zapato"}' --time
```

Da el plan de ejecución, mide los datos frente al `COUNT(*)` y dice qué no va a
escalar y por qué.

**6. ¿Se está tragando una excepción?**

Por defecto, un filtro que falla se registra en el log y la búsqueda sigue. Para
que reviente y lo veas:

```php
SearchSurge::get(Deal::class, $data, ['strict' => true]);
```

**7. ¿Cambiaste un filtro y no se refleja?**

En producción el descubrimiento se cachea. Si compilaste el manifiesto:

```bash
php artisan search-surge:clear
```

---

## Contribuir

```bash
git clone https://github.com/innoboxrr/search-surge.git
cd search-surge
composer install
composer check      # estilo + análisis estático + tests
```

`composer check` es exactamente lo que corre CI: si pasa en local, pasa allí.

### La suite

```bash
composer test                        # SQLite, rápido
composer coverage                    # con informe (necesita pcov o xdebug)
DB_CONNECTION=mysql composer test    # contra MySQL
DB_CONNECTION=pgsql composer test    # contra PostgreSQL
```

Se corre sobre los tres motores porque el paquete adapta el SQL a cada uno. No es
teórico: correr la suite sobre MySQL destapó **dos fallos del analizador** que
llevaban ahí desde el primer día, porque sus expresiones regulares solo
contemplaban el entrecomillado de SQLite.

Si tu afirmación depende de la forma del SQL, usa `assertSqlHas()` en lugar de
`assertStringContainsString()`: normaliza el entrecomillado y así vale para los
tres motores.

### Qué se exige para entrar

CI bloquea la publicación si algo de esto falla:

| | |
|---|---|
| Estilo | Pint, preset de Laravel |
| Análisis estático | PHPStan nivel 5 con larastan, sin errores |
| Cobertura | mínimo del 88% de líneas |
| Tests | 4 combinaciones de PHP y Laravel, más MySQL y PostgreSQL |

Y el tag se crea **después** de que todo eso pase: el workflow de versionado
cuelga del resultado de los tests, no del push.

### Versionado

El tag lo crea CI leyendo el último mensaje de commit: `#major`, `#minor`, o
patch si no hay marcador.

---

## Migrar de v2 a v3

**No hay que tocar nada para que siga funcionando.** `new Builder()`,
`->get($model, $data, $options)`, `filtersPath`, `setBasePath()` y la firma
`apply(Builder $query, DataContainer $data)` se comportan igual.

Lo que sí cambia de comportamiento:

1. **`updated_at_start_date` / `updated_at_end_date` ahora filtran por
   `updated_at`.** Antes filtraban por `created_at` por un copy/paste.
2. **Los booleanos de `Managed` se leen bien.** Antes `?except_view_any=false`
   se evaluaba como `true` y se saltaba la comprobación de permisos.
3. **Un `ManagedFilter` que lanza ya no se ignora.**
4. **`paginate` se acota a `max_per_page`** (1000).
5. **PHP 8.2+ y Laravel 12+.** Laravel 13 requiere PHP 8.3. Laravel 11 llegó al
   final de su soporte de seguridad en marzo de 2026 y arrastra siete avisos
   abiertos, así que Composer se niega a instalarlo: quien siga ahí se queda en
   la 2.0.6.

Opcional, cuando quieras aprovecharlo: quita los `filtersPath`, añade `$keys`,
mete `search-surge:cache` en el deploy, pasa los exports a `lazy()` y pásale
`search-surge:explain` a tus listados más pesados.

---

## Tests

```bash
composer install
composer check     # estilo + analisis estatico + tests
```

490 tests sobre SQLite, MySQL y PostgreSQL, con 4 combinaciones de PHP y
Laravel. 95% de cobertura de lineas combinando motores (91,7% en la ejecucion de
SQLite, que es la que mide CI: lo que falta ahi son las ramas de MySQL y
PostgreSQL, que cubren sus propios jobs). PHPStan sin errores y Pint sobre todo
el codigo.

Ver [Contribuir](#contribuir) para el detalle. Incluyen inyección en todas las superficies
de entrada, filtros que devuelven basura o lanzan, manifiestos corruptos,
paginación con empates y un recorrido completo por HTTP.

## Licencia

MIT. Ver [LICENSE.md](LICENSE.md).

## Soporte

[Issues en GitHub](https://github.com/innoboxrr/search-surge/issues).

## Donaciones

Si el paquete te sirve, puedes apoyar el desarrollo
[aquí](https://donate.stripe.com/9AQ8yZc4x3DifCw9AC).
