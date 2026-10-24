<?php

declare(strict_types=1);

namespace DealNews\Inngest\ValueObject;

/**
 * Base class for value objects that travel to and from Inngest as JSON
 *
 * Follows the moonspot/value-objects pattern (public typed properties,
 * toArray/fromArray, JsonSerializable) without the dependency, and adds the
 * bits the Inngest wire format needs:
 *
 * - **Wire key mapping.** PHP properties are snake_case, but Inngest mixes
 *   snake_case and camelCase (`appName`, `run_id`). Map a property to its
 *   wire key in `WIRE_KEYS`.
 * - **Null omission.** JS drops `undefined` fields when stringifying, and
 *   Inngest relies on optional fields being absent rather than `null`. Null
 *   properties are left out unless listed in `KEEP_NULL`.
 * - **Empty objects.** An empty PHP array encodes as `[]`, but some fields
 *   must be `{}`. Properties listed in `OBJECT_KEYS` encode as `{}` when
 *   empty.
 * - **Typed collections.** Arrays of value objects (lists or keyed maps)
 *   are declared in `COLLECTION_TYPES` so `fromArray()` can hydrate them.
 *
 * Usage:
 *
 * ```php
 * class AppInfo extends ValueObject {
 *     protected const WIRE_KEYS = ['app_name' => 'appName'];
 *
 *     public string $app_name = '';
 *     public ?string $app_version = null;
 * }
 *
 * json_encode(new AppInfo()); // {"appName":""}
 * ```
 *
 * Edge cases:
 *
 * - Uninitialized typed properties are treated as "not set" and omitted.
 * - `fromArray()` calls the constructor with no arguments when it has no
 *   required parameters (so promoted properties get their defaults), and
 *   skips it otherwise. Don't use readonly properties: they can't be set
 *   from this base class.
 * - Unknown keys in `fromArray()` input are ignored.
 *
 * @package DealNews\Inngest\ValueObject
 */
abstract class ValueObject implements \JsonSerializable {

    /**
     * Map of PHP property name => wire key, for keys that differ
     *
     * @var array<string, string>
     */
    protected const WIRE_KEYS = [];

    /**
     * Properties that are encoded as `null` instead of being omitted
     *
     * @var list<string>
     */
    protected const KEEP_NULL = [];

    /**
     * Array properties that must encode as a JSON object (`{}`) when empty
     *
     * @var list<string>
     */
    protected const OBJECT_KEYS = [];

    /**
     * Map of property name => value object class for arrays of value objects
     *
     * Keys of the array are preserved, so this works for lists and maps.
     *
     * @var array<string, class-string<ValueObject>>
     */
    protected const COLLECTION_TYPES = [];

    /**
     * Creates an instance from a wire-format array
     *
     * Accepts wire keys or PHP property names. Nested value objects,
     * collections, and backed enums are hydrated based on property types
     * and `COLLECTION_TYPES`.
     *
     * @param array<mixed> $data Decoded JSON data
     *
     * @throws \InvalidArgumentException When a value can't be converted to
     *                                   the property's type
     *
     * @return static
     */
    public static function fromArray(array $data): static {
        $reflection = new \ReflectionClass(static::class);
        $required   = $reflection->getConstructor()
            ?->getNumberOfRequiredParameters() ?? 0;
        $object = $required === 0 ?
            $reflection->newInstance() :
            $reflection->newInstanceWithoutConstructor();
        $properties = static::wireToPropertyMap();

        foreach ($data as $key => $value) {
            $property = $properties[$key] ?? null;
            if ($property === null) {
                continue;
            }

            $object->hydrateProperty(
                $reflection->getProperty($property),
                $value
            );
        }

        return $object;
    }

    /**
     * Creates an instance from a JSON string
     *
     * @param string $json JSON object
     *
     * @throws \JsonException            When the JSON is invalid
     * @throws \InvalidArgumentException When the JSON is not an object
     *
     * @return static
     */
    public static function fromJson(string $json): static {
        $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($data)) {
            throw new \InvalidArgumentException(
                'JSON must decode to an object',
                1001
            );
        }

        return static::fromArray($data);
    }

    /**
     * Returns the wire-format array representation
     *
     * Empty `OBJECT_KEYS` properties are plain empty arrays here; use
     * `jsonSerialize()` or `toJson()` to get `{}` in JSON output.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array {
        return $this->export(false);
    }

    /**
     * Returns data for json_encode(), with empty objects preserved as `{}`
     *
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array {
        return $this->export(true);
    }

    /**
     * Returns the JSON encoding of this object
     *
     * Uses unescaped slashes and unicode to match JS `JSON.stringify()`.
     *
     * @throws \JsonException When encoding fails
     *
     * @return string
     */
    public function toJson(): string {
        return json_encode(
            $this,
            JSON_UNESCAPED_SLASHES |
            JSON_UNESCAPED_UNICODE |
            JSON_THROW_ON_ERROR
        );
    }

    /**
     * Builds the wire-format array
     *
     * @param bool $for_json Whether empty `OBJECT_KEYS` arrays become `{}`
     *
     * @return array<string, mixed>
     */
    protected function export(bool $for_json): array {
        $result = [];

        foreach (static::publicProperties() as $property) {
            $name = $property->getName();
            if (!$property->isInitialized($this)) {
                continue;
            }

            $value     = $property->getValue($this);
            $keep_null = in_array($name, static::KEEP_NULL, true);
            if ($value === null && !$keep_null) {
                continue;
            }

            $value = static::exportValue($value, $for_json);

            if (
                $for_json     &&
                $value === [] &&
                in_array($name, static::OBJECT_KEYS, true)
            ) {
                $value = new \stdClass();
            }

            $result[static::WIRE_KEYS[$name] ?? $name] = $value;
        }

        return $result;
    }

    /**
     * Converts a single value to its wire representation
     *
     * @param mixed $value    Value to convert
     * @param bool  $for_json Whether this export is for JSON encoding
     *
     * @throws \LogicException When an object can't be exported
     *
     * @return mixed
     */
    protected static function exportValue(mixed $value, bool $for_json): mixed {
        $result = $value;

        if ($value instanceof ValueObject) {
            $result = $value->export($for_json);
        } elseif ($value instanceof \BackedEnum) {
            $result = $value->value;
        } elseif ($value instanceof \JsonSerializable) {
            $result = $value->jsonSerialize();
        } elseif (is_array($value)) {
            $result = [];
            foreach ($value as $key => $item) {
                $result[$key] = static::exportValue($item, $for_json);
            }
        } elseif (is_object($value) && !$value instanceof \stdClass) {
            throw new \LogicException(
                'Cannot export object of class ' . $value::class,
                1002
            );
        }

        return $result;
    }

    /**
     * Sets a property from a raw decoded value, converting as needed
     *
     * @param \ReflectionProperty $property Property to set
     * @param mixed               $value    Raw decoded value
     *
     * @throws \InvalidArgumentException When conversion fails
     *
     * @return void
     */
    protected function hydrateProperty(
        \ReflectionProperty $property,
        mixed $value
    ): void {
        $name = $property->getName();
        $type = $property->getType();

        if ($value === null) {
            // Leave non-nullable properties at their default rather than
            // failing on a null from the wire.
            if ($type === null || $type->allowsNull()) {
                $property->setValue($this, null);
            }

            return;
        }

        if (isset(static::COLLECTION_TYPES[$name]) && is_array($value)) {
            $class = static::COLLECTION_TYPES[$name];
            $items = [];
            foreach ($value as $key => $item) {
                $items[$key] = is_array($item) ?
                    $class::fromArray($item) :
                    $item;
            }
            $value = $items;
        } elseif (
            $type instanceof \ReflectionNamedType &&
            !$type->isBuiltin()
        ) {
            $value = static::convertToClass($type->getName(), $value, $name);
        }

        try {
            $property->setValue($this, $value);
        } catch (\TypeError $e) {
            throw new \InvalidArgumentException(
                "Invalid value for property $name: " . $e->getMessage(),
                1003,
                $e
            );
        }
    }

    /**
     * Converts a raw value to a value object or backed enum instance
     *
     * @param string $class    Target class name
     * @param mixed  $value    Raw decoded value
     * @param string $property Property name, for error messages
     *
     * @throws \InvalidArgumentException When an enum value is unknown
     *
     * @return mixed
     */
    protected static function convertToClass(
        string $class,
        mixed $value,
        string $property
    ): mixed {
        $result = $value;

        if (is_subclass_of($class, ValueObject::class) && is_array($value)) {
            $result = $class::fromArray($value);
        } elseif (
            is_subclass_of($class, \BackedEnum::class) &&
            (is_string($value) || is_int($value))
        ) {
            $result = $class::tryFrom($value);
            if ($result === null) {
                throw new \InvalidArgumentException(
                    "Invalid value for property $property: " .
                    var_export($value, true),
                    1004
                );
            }
        }

        return $result;
    }

    /**
     * Returns the public, non-static properties of this class
     *
     * @return list<\ReflectionProperty>
     */
    protected static function publicProperties(): array {
        $reflection = new \ReflectionClass(static::class);
        $public     = $reflection->getProperties(
            \ReflectionProperty::IS_PUBLIC
        );
        $properties = [];

        foreach ($public as $property) {
            if (!$property->isStatic()) {
                $properties[] = $property;
            }
        }

        return $properties;
    }

    /**
     * Returns a lookup of accepted input key => property name
     *
     * Both the wire key and the PHP property name are accepted.
     *
     * @return array<string, string>
     */
    protected static function wireToPropertyMap(): array {
        $map = [];

        foreach (static::publicProperties() as $property) {
            $name           = $property->getName();
            $wire_key       = static::WIRE_KEYS[$name] ?? $name;
            $map[$name]     = $name;
            $map[$wire_key] = $name;
        }

        return $map;
    }
}
