<?php

namespace Innoboxrr\SearchSurge\Search\Support;

use ArrayAccess;
use Carbon\CarbonInterface;
use Countable;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use IteratorAggregate;
use JsonSerializable;
use Traversable;

/**
 * Contenedor de los datos entrantes de una búsqueda.
 *
 * Se comporta como el `Request` al que sustituyó (acceso por propiedad), pero
 * además ofrece casteos explícitos. Eso importa: lo que llega por query string
 * son strings, y en PHP `'false' == true` es verdadero. Usar boolean() en vez
 * de comparar contra true evita esa clase entera de errores.
 *
 * @implements ArrayAccess<string, mixed>
 * @implements IteratorAggregate<string, mixed>
 */
class DataContainer implements Arrayable, ArrayAccess, Countable, IteratorAggregate, JsonSerializable
{
    /** @var array<string, mixed> */
    private array $data;

    /** @param array<string, mixed> $data */
    public function __construct(array $data)
    {
        $this->data = $data;
    }

    /* -----------------------------------------------------------------
     | Lectura
     | ----------------------------------------------------------------- */

    /**
     * Valor por clave, con soporte de notación de puntos.
     */
    public function get(string $name, mixed $default = null): mixed
    {
        return Arr::get($this->data, $name, $default);
    }

    /**
     * Alias familiar para quien viene de Request.
     */
    public function input(string $name, mixed $default = null): mixed
    {
        return $this->get($name, $default);
    }

    /**
     * ¿Existe la clave? (aunque su valor sea null)
     */
    public function has(string $name): bool
    {
        return Arr::has($this->data, $name);
    }

    /**
     * ¿Existe al menos una de estas claves?
     *
     * @param array<int, string>|string $names
     */
    public function hasAny(array|string $names): bool
    {
        foreach ((array) $names as $name) {
            if ($this->has($name)) {
                return true;
            }
        }

        return false;
    }

    /**
     * ¿Existe la clave y trae algo utilizable? Cadenas vacías y arrays vacíos
     * cuentan como ausentes, que es lo que espera un filtro.
     */
    public function filled(string $name): bool
    {
        $value = $this->get($name);

        if ($value === null || $value === '' || $value === []) {
            return false;
        }

        return ! (is_string($value) && trim($value) === '');
    }

    /**
     * ¿Al menos una de estas claves viene con contenido?
     *
     * @param array<int, string>|string $names
     */
    public function anyFilled(array|string $names): bool
    {
        foreach ((array) $names as $name) {
            if ($this->filled($name)) {
                return true;
            }
        }

        return false;
    }

    /* -----------------------------------------------------------------
     | Casteos
     | ----------------------------------------------------------------- */

    /**
     * Booleano con la misma semántica que Request::boolean():
     * "1", "true", "on" y "yes" son true; todo lo demás false.
     */
    public function boolean(string $name, bool $default = false): bool
    {
        if (! $this->has($name)) {
            return $default;
        }

        return filter_var($this->get($name), FILTER_VALIDATE_BOOLEAN);
    }

    public function integer(string $name, int $default = 0): int
    {
        $value = $this->get($name);

        return is_numeric($value) ? (int) $value : $default;
    }

    public function float(string $name, float $default = 0.0): float
    {
        $value = $this->get($name);

        return is_numeric($value) ? (float) $value : $default;
    }

    public function string(string $name, string $default = ''): string
    {
        $value = $this->get($name);

        return is_scalar($value) ? (string) $value : $default;
    }

    /**
     * Siempre un array, venga un array, un valor suelto o una lista separada
     * por comas ("1,2,3"), que es como los front-ends mandan los multi-select.
     *
     * @return array<int, mixed>
     */
    public function array(string $name): array
    {
        $value = $this->get($name);

        if ($value === null || $value === '') {
            return [];
        }

        if (is_array($value)) {
            return array_values($value);
        }

        if (is_string($value) && str_contains($value, ',')) {
            return array_values(array_filter(array_map('trim', explode(',', $value)), static fn ($v): bool => $v !== null && $v !== ''));
        }

        return [$value];
    }

    /**
     * Fecha parseada, o null si viene vacía o no se puede interpretar.
     */
    public function date(string $name, ?string $format = null, ?string $timezone = null): ?CarbonInterface
    {
        if (! $this->filled($name)) {
            return null;
        }

        $value = $this->get($name);

        try {
            if ($format !== null) {
                // createFromFormat de Illuminate lanza en vez de devolver false,
                // asi que el catch de abajo es el que cubre el formato invalido.
                return Carbon::createFromFormat($format, (string) $value, $timezone);
            }

            return Carbon::parse($value, $timezone);
        } catch (\Throwable) {
            return null;
        }
    }

    /* -----------------------------------------------------------------
     | Subconjuntos
     | ----------------------------------------------------------------- */

    /**
     * @param array<int, string>|string $keys
     * @return array<string, mixed>
     */
    public function only(array|string $keys): array
    {
        return Arr::only($this->data, (array) $keys);
    }

    /**
     * @param array<int, string>|string $keys
     * @return array<string, mixed>
     */
    public function except(array|string $keys): array
    {
        return Arr::except($this->data, (array) $keys);
    }

    /** @return array<string, mixed> */
    public function all(): array
    {
        return $this->data;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return $this->data;
    }

    /** @return array<int, string> */
    public function keys(): array
    {
        return array_keys($this->data);
    }

    /* -----------------------------------------------------------------
     | Escritura
     | ----------------------------------------------------------------- */

    public function set(string $name, mixed $value): static
    {
        Arr::set($this->data, $name, $value);

        return $this;
    }

    /**
     * Rellena una clave solo si no venía definida.
     */
    public function fillMissing(string $name, mixed $value): static
    {
        if (! $this->has($name)) {
            $this->set($name, $value);
        }

        return $this;
    }

    /**
     * @param array<string, mixed> $data
     */
    public function merge(array $data): static
    {
        $this->data = array_merge($this->data, $data);

        return $this;
    }

    public function forget(string $name): static
    {
        Arr::forget($this->data, $name);

        return $this;
    }

    /* -----------------------------------------------------------------
     | Interfaces mágicas
     | ----------------------------------------------------------------- */

    public function __get(string $name): mixed
    {
        return $this->data[$name] ?? null;
    }

    public function __set(string $name, mixed $value): void
    {
        $this->data[$name] = $value;
    }

    /**
     * Sin esto `isset($data->foo)` siempre devolvía false y `empty()` mentía.
     */
    public function __isset(string $name): bool
    {
        return isset($this->data[$name]);
    }

    public function __unset(string $name): void
    {
        unset($this->data[$name]);
    }

    public function offsetExists(mixed $offset): bool
    {
        return $this->has((string) $offset);
    }

    public function offsetGet(mixed $offset): mixed
    {
        return $this->get((string) $offset);
    }

    public function offsetSet(mixed $offset, mixed $value): void
    {
        if ($offset === null) {
            $this->data[] = $value;

            return;
        }

        $this->set((string) $offset, $value);
    }

    public function offsetUnset(mixed $offset): void
    {
        $this->forget((string) $offset);
    }

    public function count(): int
    {
        return count($this->data);
    }

    public function getIterator(): Traversable
    {
        return new \ArrayIterator($this->data);
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return $this->data;
    }
}
