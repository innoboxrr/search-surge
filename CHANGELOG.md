# Changelog de SearchSurge

Todas las modificaciones notables del proyecto se documentan aquí.
Este proyecto sigue [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [3.0.2]

### Added

- **El camino por defecto de `EngineFilter` esta probado.** `ids()` delega en
  Laravel Scout, y de ese metodo solo se probaba hasta ahora la rama de error.
  La via que el README documenta como "no hace falta nada mas" -la puerta de
  entrada a Elasticsearch, Algolia, Meilisearch y Typesense- no la habia
  ejecutado nunca nadie. Se anade Scout como dependencia de desarrollo y ocho
  pruebas con el driver `collection`, que resuelve la busqueda con el propio
  Eloquent: se verifica el contrato -que se llama a search(), que se respeta el
  limite, que los ids acotan la consulta y mandan en el orden, que los filtros
  SQL siguen recortando y que la autorizacion sigue aplicandose- sin levantar
  ningun motor.
- Pruebas de las ramas que quedaban sin recorrer: los "no hay nada que hacer"
  de los comandos, los limites de la paginacion y los caminos de degradacion.

### Fixed

- **La suite agotaba las conexiones de PostgreSQL** (`sorry, too many clients
  already`). Cada prueba levanta su propia aplicacion y con ella su conexion, y
  ninguna se cerraba: PostgreSQL admite 100 por defecto y la suite tiene casi
  500 pruebas. Sobre SQLite en memoria no se veia, porque mueren con el proceso.
- **El marcador de version tiene que ir en su propia linea.** Buscarlo en
  cualquier parte del mensaje hacia que mencionarlo de pasada disparara el salto
  de version, que es como se publico `innoboxrr/traits` 2.0.0 sin quererlo.

### Changed

- `.gitattributes` deja fuera del archivo de distribucion los tests, la
  configuracion de CI y las herramientas de desarrollo. Pesaban mas que el
  propio codigo (293 KB frente a 272 KB) y acababan igualmente en el vendor/ de
  quien instala el paquete.

Cobertura: 490 pruebas, 91,7% de lineas en la ejecucion de SQLite y 95,0%
combinando SQLite y MySQL. Lo que falta en cada una son las ramas del otro
motor, que se cubren en su propio job.

## [3.0.1]

Publicada sin entrada propia; se documenta aqui a posteriori.

### Fixed

- **El analizador estaba ciego en MySQL.** Sus expresiones regulares solo
  contemplaban el entrecomillado con comillas dobles, asi que en el motor mas
  usado no detectaba ni el OR de LIKE entre columnas ni las funciones
  envolviendo una columna.
- **Y ciego en PostgreSQL**, que no envuelve la columna en una funcion sino que
  la castea (`"created_at"::date`). El efecto sobre el indice es el mismo.
- **Falso positivo en MySQL**: cuando el motor resuelve la consulta durante la
  optimizacion -"no matching row in const table"- no llega a leer la tabla. El
  analizador lo reportaba como "no usa ningun indice", que es lo contrario.
- **El orden por relevancia reventaba en PostgreSQL** con
  `array_position(text[], bigint) does not exist`. PostgreSQL deduce el tipo del
  `ARRAY[...]` al analizar la consulta, antes de saber que le van a llegar en
  los parametros. Ahora se castean los dos lados a texto.
- **El generador escribia en cualquier sitio** con una clase sin namespace, y el
  resultado dependia del separador de rutas del sistema.
- Un caracter de control invisible (0x08 donde debia ir ``) desactivaba la
  normalizacion del SQL en las pruebas: la expresion compilaba y no casaba
  nunca.

### Changed

- **Nada se publica sin pasar los tests.** El workflow de versionado colgaba de
  `push`, asi que corria en paralelo con la suite: un commit roto se etiquetaba
  igual y Packagist lo publicaba. Ahora cuelga del resultado de los tests y hace
  checkout del commit exacto que paso.
- CI corre la suite sobre **SQLite, MySQL y PostgreSQL**, con un minimo de
  cobertura del 88%. Los cinco fallos de arriba salieron de ahi: ninguno era
  visible sobre un solo motor.

## [3.0.0]

Compatibilidad con Laravel 13, descubrimiento dinámico de filtros, consultas
sargables, búsqueda de texto y análisis de escalabilidad. La API de v2 sigue
funcionando sin cambios.

**Requiere Laravel 12 o 13 y PHP 8.2+.** Laravel 11 llegó al final de su soporte
de seguridad en marzo de 2026 y arrastra siete avisos abiertos, asi que Composer
se niega a instalarlo salvo que se desactive el bloqueo de avisos. Prometer un
soporte que no se puede ni instalar ni verificar no seria soporte. Quien siga en
Laravel 11 se queda en 2.0.6.

### Added

- **Soporte para Laravel 12 y 13** (PHP 8.2+; Laravel 13 requiere PHP 8.3).
  El `composer.json` declara por fin sus dependencias: antes no había ninguna
  restricción de `php` ni de `illuminate/*`.
- **Descubrimiento por convención.** `App\Models\User` busca sus filtros en
  `App\Models\Filters\User` sin configurar nada. El directorio real se deduce
  del autoloader PSR-4 de Composer, así que funciona igual en `vendor/` o como
  *path repository*.
- **`FilterRegistry`** con `SearchSurge::registerFilters()`,
  `registerFilterMap()` y `registerNamespace()`, para que un paquete declare sus
  filtros en una línea desde su ServiceProvider.
- **Manifiesto compilado**: `php artisan search-surge:cache` escribe el mapa
  modelo → filtros a un archivo PHP. En producción la resolución pasa a ser una
  búsqueda en un array, sin tocar disco ni autoloader.
  `search-surge:clear` lo borra.
- **`php artisan search-surge:filters`** lista qué filtros se aplican a cada
  modelo, en qué orden y con qué claves.
- **Trait `HasSearchSurge`** con `surgeSearch()`, `surgeQuery()`, `surgeCount()`,
  `surgeLazy()` y el scope encadenable `->surge()`.
- **Contrato `Filter`** y dos propiedades estáticas opcionales en los filtros:
  `$keys` (para que el filtro se salte cuando ninguna de sus claves viene) y
  `$priority` (orden de aplicación).
- **Métodos nuevos en el Builder**: `query()`, `count()`, `exists()`, `first()`,
  `lazy()`, `lazyById()` y `cursor()`.
- **Paginadores `simple` y `cursor`**, que se saltan el `COUNT(*)`.
- **Opciones nuevas**: `query` (partir de un Builder existente), `columns`,
  `with`, `withCount`, `withoutGlobalScopes`, `perPage`, `maxPerPage`,
  `paginator`.
- **Archivo de configuración publicable** (`search-surge-config`).
- **`DataContainer`**: `get()` con notación de puntos, `filled()`, `anyFilled()`,
  `hasAny()`, `boolean()`, `integer()`, `float()`, `string()`, `array()`,
  `date()`, `only()`, `except()`, `merge()`, `forget()`, `__isset()`, `__set()`
  e implementación de `ArrayAccess`, `Countable`, `IteratorAggregate`,
  `Arrayable` y `JsonSerializable`.
- **`Order::orderByAny()`** (lista blanca, sintaxis `"name,-created_at"`) y
  **`Order::fallback()`** (orden por defecto estable, necesario para que la
  paginación no repita ni se salte filas).
- **Caché del `COUNT(*)`** del paginador (`pagination.count_cache`). Sobre 1M de
  filas el conteo cuesta entre 12 y 500 veces más que la página de datos y se
  repite en cada carga.
- **Tope de página** (`pagination.max_page`) que lanza `PageLimitExceededException`
  —renderizada como 400— en vez de servir un `OFFSET` de millones de filas.
- **Desempate estable** (`pagination.stable_order`): añade la clave primaria al
  `ORDER BY` existente para que la paginación no repita ni se salte filas cuando
  la columna de orden tiene valores repetidos.
- El cursor de la paginación por cursor se lee de `$data['cursor']`, igual que
  `page`, para que el patrón `$request->all()` funcione en ambos modos.

#### Busqueda de texto

- **`Utils\TextSearch`** con `prefix()`, `contains()`, `suffix()` y `fullText()`.
  Sobre 1M de filas en MySQL: prefijo 14,7 ms, FULLTEXT 356 ms, contiene 26.912 ms.
  Las tres escapan los comodines del usuario -sin eso `?q=%` significa
  "devuelvelo todo"- con clausula `ESCAPE` explicita y un caracter portable,
  porque la barra invertida obliga a `ESCAPE '\'` en MySQL y SQLite rechaza eso
  mismo. Usan `ILIKE` en PostgreSQL para igualar el comportamiento de MySQL.
- **`Utils\Relevance`** para conservar el orden que devuelve un motor externo:
  `FIELD()` en MySQL, `array_position()` en PostgreSQL y `CASE` en el resto.
- **`Filters\EngineFilter`**, base para delegar la busqueda en Elasticsearch,
  Algolia, Meilisearch o Typesense. El motor pone la relevancia y SQL pone los
  filtros exactos y la autorizacion, que es lo que un motor externo hace mal.
  Funciona con Laravel Scout sin requerirlo, o con un cliente propio
  sobrescribiendo `ids()`.

#### Diagnostico

- **`php artisan search-surge:explain`**: analiza el SQL y el plan de ejecucion
  (MySQL, PostgreSQL y SQLite) y avisa de lo que no va a escalar. Los hallazgos
  criticos devuelven codigo 1, asi que sirve en CI.
- **`Support\QueryAnalyzer`** detecta `LIKE '%...%'`, OR de LIKE entre columnas,
  funciones envolviendo columnas, falta de `ORDER BY`, full scans, filesorts y
  tablas temporales.
- **`php artisan search-surge:filter`** genera filtros de tipo `basic`, `text`,
  `date`, `engine` o `managed` en el sitio donde el descubrimiento los busca.

#### Contrato de entrada

- **`SearchSurge::schema()`** y **`unknownParameters()`**: las `$keys` de los
  filtros se convierten en el contrato de entrada del endpoint. Responde a "que
  parametros acepta esto" y detecta un `?nombre=` escrito en vez de `?name=`.
- **`search-surge:filters --json`** vuelca ese contrato para generar
  documentacion, OpenAPI o los tipos del front-end.

#### Observabilidad

- **`Events\SearchExecuted`** con el modelo, los filtros que entraron de verdad,
  el SQL, los bindings y el tiempo. Los valores de los parametros no van en
  `context()`: pueden ser datos personales.
- **`observability.slow_threshold`** registra las busquedas lentas. El log de
  consultas de Laravel no vale para esto porque no sabe que filtros entraron.

#### Otros

- Cualquier filtro puede declararse critico con `public static bool $critical`.
  Lo hace `EngineFilter`: con el motor caido, devolver la tabla entera es peor
  que devolver un error.
- `strict` se puede fijar por `$options`, no solo por configuracion.
- Suite de tests: 329 casos sobre Laravel 11, 12 y 13, con CI en GitHub Actions.
  Incluye pruebas de inyección sobre todas las superficies de entrada, filtros
  que devuelven basura o lanzan, manifiestos corruptos, recorrido completo por
  HTTP y verificación de la paginación con empates.

#### Menos archivos y mas piezas

- **Filtros comunes** (`Filters\Common\{IdFilter,TimestampsFilter,SoftDeletesFilter}`).
  Un modelo nuevo responde a `?id=`, `?ids=`, `?id_not=`, los rangos de fecha,
  `?orderBy=` y `?trashed=` sin crear un solo archivo. Leen la configuracion del
  propio modelo: la clave primaria real aunque sea un uuid, los nombres reales de
  las columnas de timestamps, y si usa SoftDeletes.

  No pisan a los tuyos: un comun se descarta si el modelo ya tiene algo
  equivalente, por nombre corto de clase o por solape de claves declaradas. Sin
  la segunda regla, un modelo con CreationFilter y UpdatedFilter propios
  recibiria ademas el TimestampsFilter y las fechas se filtrarian dos veces.

- **`Utils\NumericFilterQuery`**: rangos y operadores para columnas numericas,
  con el mismo vocabulario que las fechas. Un valor no numerico se ignora en vez
  de convertirse en 0, que es la diferencia entre "no filtres por precio" y
  "dame los de precio 0".

- **`Utils\SetFilterQuery`**: conjuntos con lista blanca. Si se pidio algo y nada
  sobrevive al filtro, devuelve cero filas en vez de ignorar la condicion. El
  literal `null` en la lista se traduce a IS NULL.

- **`Utils\RelationFilterQuery`**: existencia, conteo y columnas del otro lado.
  Usa whereHas y no intenta adivinar una estrategia, porque MySQL 8 unifica
  whereHas, whereIn con subconsulta y JOIN en el mismo plan (112,6 / 74,2 /
  90,1 ms sobre 1M de filas). Lo que si cambia las cosas, por 27x, es filtrar la
  clave foranea directamente cuando esta en la propia tabla.

- **`Support\Driver`**: centraliza saber que motor hay detras. `getConnection()`
  devuelve `ConnectionInterface`, que no declara `getDriverName()`, asi que una
  conexion personalizada haria fatal en las cuatro piezas que adaptan el SQL.

- **PHPStan (larastan) y Pint** con configuracion propia, scripts de composer
  (`composer check`) y un job de CI que corre las dos cosas antes que los tests.

#### Corregido en la revision de calidad

- `array_filter(..., 'strlen')` en cuatro sitios: con un entero en la lista es
  deprecacion en PHP 8.1 y TypeError en modo estricto.
- Comparacion muerta en `DataContainer::date()`: `createFromFormat` de Illuminate
  lanza en vez de devolver false, asi que el `=== false` nunca se cumplia.
- Catch inalcanzable de `ReflectionException` en `ModelScanner`.

#### Matiz sobre los filtros comunes

- Una lista explicita en `$options['filters']` es **exhaustiva**: no recibe los
  filtros comunes. Colar filtros que nadie pidio justo donde mas control se
  espera seria una sorpresa desagradable. Lo declarado a nivel de modelo
  (registro, manifiesto, convencion) si los recibe.

### Fixed

- **`UpdatedFilterQuery` filtraba por `created_at`.** El rango
  `updated_at_start_date` / `updated_at_end_date` escribía sobre la columna
  equivocada por un copy/paste.
- **Los booleanos de `Managed` se evaluaban al revés.** `?except_view_any=false`
  llegaba como la cadena `"false"`, y en PHP `'false' == true` es verdadero, así
  que se saltaba la comprobación de permisos justo cuando se pedía lo contrario.
  Ahora se usa la misma semántica que `Request::boolean()`.
- **La caché de filtros expiraba en 24 minutos, no en 24 horas.** El TTL se
  pasaba en minutos, pero Laravel lo interpreta en segundos desde 5.8.
- **La clave de caché no distinguía modelos homónimos.** Usaba solo el nombre
  corto, así que `App\Models\Deal` e `Innoboxrr\Deals\Models\Deal` compartían
  entrada.
- **Un filtro que no devolvía el builder rompía la búsqueda.** Ahora se aceptan
  también los filtros que mutan la consulta sin devolverla.
- **Un filtro inexistente registraba un error en cada petición.** Ahora
  simplemente no se aplica.
- `paginate=''` producía un paginador con tamaño de página 0.
- El escaneo de filtros recogía cualquier archivo del directorio, no solo `.php`,
  y el orden dependía del sistema de archivos.
- El estado del Builder se filtraba entre dos llamadas a `get()` de la misma
  instancia.
- Los limites de los rangos de fecha se pasan como `Y-m-d` en vez de
  `Y-m-d H:i:s`. Para MySQL y PostgreSQL es indiferente, pero en SQLite -que
  compara cadenas- una columna `DATE` que guarda `2026-01-15` nunca casaba
  contra `2026-01-15 00:00:00`.
- El cursor de la paginacion por cursor no se leia de `$data`, solo de la
  peticion, asi que el patron `$request->all()` no funcionaba en ese modo.

### Changed

- **Los filtros de fecha ya no usan `whereDate()`.** Envolver la columna en una
  función impide usar su índice. Ahora cada operador se traduce a un rango
  semiabierto sobre la columna desnuda. Verificado con `EXPLAIN QUERY PLAN`:
  `SCAN` pasa a `SEARCH ... USING COVERING INDEX`, con resultados idénticos para
  los seis operadores.
- **Los fallos de un filtro de autorización ya no se tragan.** Los descendientes
  de `Utils\Managed` se aplican primero y sus excepciones se propagan: seguir
  adelante con la consulta sin acotar sería una fuga de datos. Los demás filtros
  mantienen el comportamiento de v2 (log y continuar) salvo que actives
  `search-surge.strict`.
- **`paginate` se acota a `max_per_page`** (1000 por defecto, `null` desactiva).
- El descubrimiento se memoiza en memoria durante todo el request, y solo se
  cachea entre requests en producción, para que en desarrollo un filtro nuevo se
  vea al instante.
- El modelo se instancia con `new` salvo que esté enlazado en el contenedor.
  Además se valida que sea un modelo de Eloquent, con un mensaje claro.
- `Utils\Managed` y `Utils\Order` aceptan tanto un `DataContainer` como
  cualquier objeto con las propiedades, como antes.

### Removed

- Nada. La API pública de v2 se mantiene completa.

## [2.0.0]

### Added
- CHANGELOG.md, README.md y LICENSE.md.

### Changed
- Se sustituyó el uso de `Request` por un array `$data`, tanto al llamar a `get()`
  como en la firma de los filtros: `apply(Builder $builder, array $data)`.

## [1.0.0]

### Added
- Lanzamiento inicial.
- Configuración y ejecución de búsquedas complejas con una interfaz sencilla.
- Filtros personalizados aplicados a consultas de modelos de Laravel.
- Paginación de resultados o recuperación como colección.
- Opciones para personalizar la ruta y el espacio de nombres de los filtros.
