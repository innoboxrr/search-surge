# Innoboxrr SearchSurge para Laravel

SearchSurge es un paquete que proporciona una forma elegante y flexible de filtrar y buscar datos en tu aplicación Laravel. Utiliza una clase de construcción para permitir consultas complejas con facilidad.

## Instalación

Puedes instalar el paquete a través de composer:

\`\`\`bash
composer require innoboxrr/search-surge
\`\`\`

## Uso

La clase principal de este paquete es `Innoboxrr\SearchSurge\Search\Builder`. Así es como puedes usarla:

### Uso Básico

Primero, utiliza la clase `Builder` en tu controlador o servicio:

\`\`\`php
use Innoboxrr\SearchSurge\Search\Builder;
\`\`\`

Luego, puedes crear una nueva instancia del constructor o inyectarlo, y llamar al método `get`, pasando el modelo, los datos y la configuración opcional:

\`\`\`php
$builder = new Builder();

$resultado = $builder->get(User::class, $data, $options);
\`\`\`

### Personalización

Puedes personalizar varias partes del comportamiento de búsqueda:

- **Ruta de los Filtros**: Establece la ruta del directorio para los filtros.
- **Espacio de Nombres de los Filtros**: Establece el espacio de nombres para los filtros.

Estos se pueden establecer utilizando el array `$options` pasado al método `get`.

### Filtros

Puedes crear filtros personalizados dentro del directorio especificado en `filtersPath`. El constructor aplicará automáticamente estos filtros a tu consulta.

## Ejemplo

Supongamos que tienes un modelo de Usuario y quieres filtrar por estado activo y rol. Puedes crear filtros para estos y luego usar el constructor:

\`\`\`php
$data = [
    'active' => 1,
    'role' => 'admin',
];

$options = [
    'filtersPath' => 'app/Filters',
];

$resultado = $builder->get(User::class, $data, $options);
\`\`\`

## Contribuciones

Por favor, consulta [CONTRIBUTING.md](CONTRIBUTING.md) para más detalles.

## Seguridad

Si descubres algún problema relacionado con la seguridad, por favor envía un correo electrónico al desarrollador en lugar de utilizar el rastreador de problemas.

## Licencia

La Licencia MIT (MIT). Por favor, consulta [Archivo de Licencia](LICENSE.md) para más información.

## Soporte

Para soporte general y preguntas, por favor utiliza la [sección de problemas](https://github.com/innoboxrr/search-surge/issues) en GitHub.

## Donaciones

Si este paquete te ha sido útil y te gustaría apoyar el desarrollo continuo, considera hacer una donación en [este enlace](https://donate.stripe.com/9AQ8yZc4x3DifCw9AC).

¡Feliz búsqueda! 🚀
