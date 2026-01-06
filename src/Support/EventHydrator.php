<?php

namespace AlazziAz\LaravelDaprListener\Support;

use BackedEnum;
use Carbon\Carbon;
use DateTimeImmutable;
use DateTimeInterface;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use InvalidArgumentException;
use ReflectionAttribute;
use ReflectionClass;
use ReflectionException;
use ReflectionIntersectionType;
use ReflectionNamedType;
use ReflectionParameter;
use ReflectionProperty;
use ReflectionType;
use ReflectionUnionType;

/**
 * Framework-agnostic event hydrator that:
 * - Hydrates constructor-promoted DTOs/events from associative arrays (Dapr/CloudEvent-like payloads)
 * - Supports scalars, enums, DateTime, nested DTOs, arrays of DTOs
 * - Supports "optional" Spatie Laravel Data if installed WITHOUT requiring it
 * - Supports "optional" Illuminate Collections if installed WITHOUT requiring it
 *
 * Notes:
 * - Never hard-import Spatie classes. Detect via class_exists() and string class names.
 * - If a consumer types a property/param to a class that is not installed, PHP itself will fail
 *   before this hydrator runs. So for true optionality, event constructors should use portable
 *   types (array/iterable) OR installed-agnostic interfaces.
 */
class EventHydrator
{
    private const NO_MATCH = '__NO_MATCH__';

    /**
     * Entry point to hydrate an event from a Dapr/CloudEvent-like payload.
     *
     * @param class-string $class
     */
    public function make(string $class, array $payload): object
    {
        return $this->doMake(
            class: $class,
            payload: $payload,
            unwrapCloudEvent: true,
            allowFromPayload: true,
            allowFrom: true,
        );
    }

    /**
     * Internal object hydrator.
     *
     * @param class-string $class
     * @throws ReflectionException
     */
    protected function doMake(
        string $class,
        array  $payload,
        bool   $unwrapCloudEvent = true,
        bool   $allowFromPayload = true,
        bool   $allowFrom = true,
    ): object
    {
        // Prefer explicit factories if present.
        if ($allowFromPayload && method_exists($class, 'fromPayload')) {
            return $class::fromPayload($payload);
        }

        if ($allowFrom && method_exists($class, 'from')) {
            return $class::from($payload);
        }

        // Unwrap CloudEvent-ish wrappers.
        if ($unwrapCloudEvent) {
            $payload = $this->unwrapCloudEvent($payload);
            $payload = $payload['data'] ?? $payload;
        }

        $reflection = new ReflectionClass($class);

        if (!$reflection->isInstantiable()) {
            throw new InvalidArgumentException("Event class [$class] is not instantiable.");
        }

        $constructor = $reflection->getConstructor();

        if (!$constructor) {
            return new $class();
        }

        $flattened = Arr::dot($payload);

        $arguments = [];

        foreach ($constructor->getParameters() as $parameter) {
            $paramName = $parameter->getName();

            $rawValue = $this->resolveValue(
                original: $payload,
                flattened: $flattened,
                parameterName: $paramName
            );

            $value = $this->castParameterValue($parameter, $rawValue);

            if ($value !== null || $parameter->allowsNull()) {
                $arguments[] = $value;
                continue;
            }

            if ($parameter->isDefaultValueAvailable()) {
                $arguments[] = $parameter->getDefaultValue();
                continue;
            }

            throw new InvalidArgumentException(
                "Missing value for parameter [{$paramName}] of event [$class]."
            );
        }

        return $reflection->newInstanceArgs($arguments);
    }

    /**
     * Unwrap common CloudEvent envelope formats.
     * We keep this permissive because different emitters vary.
     */
    protected function unwrapCloudEvent(array $payload): array
    {
        // Typical CloudEvent (v1) shape: { specversion, type, source, id, time?, datacontenttype?, data: {...} }
        if (isset($payload['specversion'], $payload['data'])) {
            return $payload;
        }

        // Some emitters nest event under "data" only.
        if (array_key_exists('data', $payload) && is_array($payload['data'])) {
            return $payload;
        }

        return $payload;
    }

    /**
     * Resolve value for a given constructor parameter name.
     *
     * - First: try direct / nested access on the original payload (so `userData`
     *   can receive the whole nested array).
     * - Then: fallback to flattened last-segment matching for scalar fields like `email`, `id`, etc.
     */
    protected function resolveValue(array $original, array $flattened, string $parameterName): mixed
    {
        $candidates = [
            $parameterName,
            Str::snake($parameterName),
            Str::camel($parameterName),
            Str::studly($parameterName),
        ];

        // 1) Try direct/nested on original payload
        foreach ($candidates as $candidate) {
            if (array_key_exists($candidate, $original)) {
                return $original[$candidate];
            }

            if (Arr::has($original, $candidate)) {
                return Arr::get($original, $candidate);
            }
        }

        // 2) Fallback: flattened behavior (match by last segment)
        foreach ($flattened as $key => $value) {
            $lastSegment = Str::afterLast((string)$key, '.');

            if (in_array($lastSegment, $candidates, true)) {
                return $value;
            }
        }

        return null;
    }

    /**
     * Cast raw value according to parameter type:
     * - Scalars
     * - Enums
     * - DateTime
     * - Nested DTOs
     * - Arrays of DTOs via #[HydrateArrayOf(...)]
     * - Optional Spatie Data / DataCollection if installed (fallback to arrays otherwise)
     * - Optional Illuminate\Support\Collection if installed (fallback to arrays otherwise)
     */
    protected function castParameterValue(ReflectionParameter $parameter, mixed $raw): mixed
    {
        $type = $parameter->getType();

        // No type info => return as-is.
        if (!$type instanceof ReflectionType) {
            return $raw;
        }

        if ($raw === null) {
            return null;
        }

        // Union/Intersection types: choose first compatible.
        if ($type instanceof ReflectionUnionType || $type instanceof ReflectionIntersectionType) {
            return $this->castAgainstComplexType($parameter, $raw, $type);
        }

        if (!$type instanceof ReflectionNamedType) {
            return $raw;
        }

        // Builtins
        if ($type->isBuiltin()) {
            $name = $type->getName();

            if ($name === 'array') {
                return $this->castArrayParameter($parameter, $raw);
            }

            if ($name === 'iterable') {
                // Always normalize iterable payloads to array for portability.
                return is_array($raw) ? $raw : (array)$raw;
            }

            return $this->castBuiltin($name, $raw);
        }

        // Non-builtin class
        $className = $type->getName();

        // Optional: Spatie Laravel Data support
        $maybeSpatie = $this->castOptionalSpatieTypes($parameter, $className, $raw);
        if ($maybeSpatie !== self::NO_MATCH) {
            return $maybeSpatie;
        }

        // Optional: Illuminate Collection support
        $maybeIlluminate = $this->castOptionalIlluminateCollection($parameter, $className, $raw);
        if ($maybeIlluminate !== self::NO_MATCH) {
            return $maybeIlluminate;
        }

        // Backed enum
        if (enum_exists($className) && is_subclass_of($className, BackedEnum::class)) {
            /** @var class-string<BackedEnum> $className */
            return $className::from($raw);
        }

        // Date / DateTime
        if (is_a($className, DateTimeInterface::class, true)) {
            // Carbon is common but not mandatory; parse to Carbon if available; else DateTimeImmutable
            return class_exists(Carbon::class)
                ? Carbon::parse($raw)
                : new DateTimeImmutable((string)$raw);
        }

        // Nested DTO (array -> object)
        if (is_array($raw)) {
            // If the nested class offers a factory, allow it
            return $this->doMake(
                class: $className,
                payload: $raw,
                unwrapCloudEvent: false,
                allowFromPayload: true,
                allowFrom: true,
            );
        }

        // If raw is scalar and target expects object, leave raw (caller can validate)
        return $raw;
    }

    /**
     * Handle Union/Intersection types by trying named types in order.
     */
    protected function castAgainstComplexType(
        ReflectionParameter $parameter,
        mixed               $raw,
        ReflectionType      $type
    ): mixed
    {
        $namedTypes = [];

        if ($type instanceof ReflectionUnionType) {
            $namedTypes = $type->getTypes();
        } elseif ($type instanceof ReflectionIntersectionType) {
            $namedTypes = $type->getTypes();
        }

        foreach ($namedTypes as $named) {
            if (!$named instanceof ReflectionNamedType) {
                continue;
            }

            // Try casting as if this named type were the parameter type
            $tmpParam = $this->cloneParameterWithType($parameter, $named);
            $casted = $this->castParameterValue($tmpParam, $raw);

            // Heuristic: if cast changed or is not raw, accept; also accept null only if raw null (handled earlier)
            if ($casted !== $raw || $this->isCompatibleWithNamedType($named, $casted)) {
                return $casted;
            }
        }

        return $raw;
    }

    /**
     * Minimal helper to "simulate" a parameter with a different ReflectionNamedType.
     * PHP doesn't allow constructing ReflectionParameter; so we instead branch logic above
     * using this helper with a wrapper object (below).
     */
    protected function cloneParameterWithType(ReflectionParameter $parameter, ReflectionNamedType $type): ReflectionParameterProxy
    {
        return new ReflectionParameterProxy($parameter, $type);
    }

    protected function isCompatibleWithNamedType(ReflectionNamedType $type, mixed $value): bool
    {
        if ($value === null) {
            return $type->allowsNull();
        }

        if ($type->isBuiltin()) {
            return match ($type->getName()) {
                'int' => is_int($value),
                'float' => is_float($value),
                'string' => is_string($value),
                'bool' => is_bool($value),
                'array' => is_array($value),
                'iterable' => is_iterable($value),
                default => true,
            };
        }

        return is_object($value) && is_a($value, $type->getName());
    }

    /**
     * Handle "array" parameters, with support for arrays of DTOs using #[HydrateArrayOf].
     */
    protected function castArrayParameter(ReflectionParameter $parameter, mixed $raw): mixed
    {
        if (!is_array($raw)) {
            $raw = (array)$raw;
        }

        $attributes = $parameter->getAttributes(HydrateArrayOf::class, ReflectionAttribute::IS_INSTANCEOF);

        if (empty($attributes)) {
            // Regular array (scalar or associative)
            return $raw;
        }

        /** @var HydrateArrayOf $attr */
        $attr = $attributes[0]->newInstance();
        $itemClass = $attr->class;

        $result = [];

        foreach ($raw as $item) {
            if (is_array($item)) {
                $result[] = $this->doMake(
                    class: $itemClass,
                    payload: $item,
                    unwrapCloudEvent: false,
                    allowFromPayload: true,
                    allowFrom: true,
                );
            } else {
                $result[] = $item;
            }
        }

        return $result;
    }

    protected function castBuiltin(string $name, mixed $value): mixed
    {
        return match ($name) {
            'int' => (int)$value,
            'float' => (float)$value,
            'string' => (string)$value,
            'bool' => (bool)$value,
            'array' => (array)$value,
            default => $value,
        };
    }

    /**
     * Optional Spatie Laravel Data support WITHOUT requiring the dependency.
     *
     * Behavior:
     * - If parameter type is Spatie\LaravelData\DataCollection:
     *   - If #[HydrateArrayOf(Item::class)] exists, hydrate items
     *   - If Spatie is installed => return new DataCollection(Item::class, items)
     *   - Else => return items array
     * - If parameter type is Spatie\LaravelData\Data:
     *   - If Spatie is installed => call ::from($raw) when $raw is array (best effort)
     *   - Else => fallback to nested doMake()
     *
     * Returns:
     * - casted value, or self::NO_MATCH if not applicable
     */
    protected function castOptionalSpatieTypes(ReflectionParameter $parameter, string $className, mixed $raw): mixed
    {
        $spatieData = 'Spatie\\LaravelData\\Data';
        $spatieDataCollection = 'Spatie\\LaravelData\\DataCollection';

        // If the className equals these strings, the code that defines the event already references them.
        // This means Spatie is installed in THAT app (otherwise PHP would fail earlier).
        // Still, we keep everything conditional and avoid importing.
        if ($className === $spatieDataCollection) {
            if (!is_array($raw)) {
                $raw = (array)$raw;
            }

            $attrs = $parameter->getAttributes(HydrateArrayOf::class, ReflectionAttribute::IS_INSTANCEOF);

            if (empty($attrs)) {
                // Can't know item type => safest fallback is raw array
                return $raw;
            }

            /** @var HydrateArrayOf $attr */
            $attr = $attrs[0]->newInstance();
            $itemClass = $attr->class;

            $items = [];
            foreach ($raw as $item) {
                $items[] = is_array($item)
                    ? $this->doMake($itemClass, $item, unwrapCloudEvent: false, allowFromPayload: true, allowFrom: true)
                    : $item;
            }

            // If Spatie isn't available for some reason, fallback to array
            if (!class_exists($spatieDataCollection)) {
                return $items;
            }

            return new $spatieDataCollection($itemClass, $items);
        }

        // If parameter is a Spatie Data object (not collection)
        if (is_a($className, $spatieData, true)) {
            if (is_array($raw) && method_exists($className, 'from')) {
                // Spatie Data supports ::from(array)
                return $className::from($raw);
            }

            // fallback to normal hydration if array
            if (is_array($raw)) {
                return $this->doMake(
                    class: $className,
                    payload: $raw,
                    unwrapCloudEvent: false,
                    allowFromPayload: true,
                    allowFrom: true
                );
            }

            return $raw;
        }

        return self::NO_MATCH;
    }

    /**
     * Optional Illuminate\Support\Collection support WITHOUT requiring the dependency.
     *
     * Behavior:
     * - If parameter type is Illuminate\Support\Collection (or subclass):
     *   - If #[HydrateArrayOf(Item::class)] exists, hydrate items
     *   - If Illuminate is installed => return collect(items)
     *   - Else => return items array
     *
     * Returns:
     * - casted value, or self::NO_MATCH if not applicable
     */
    protected function castOptionalIlluminateCollection(ReflectionParameter $parameter, string $className, mixed $raw): mixed
    {
        $illuminateCollection = 'Illuminate\\Support\\Collection';

        if (!is_a($className, $illuminateCollection, true)) {
            return self::NO_MATCH;
        }

        if (!is_array($raw)) {
            $raw = (array)$raw;
        }

        $attrs = $parameter->getAttributes(HydrateArrayOf::class, ReflectionAttribute::IS_INSTANCEOF);

        if (!empty($attrs)) {
            /** @var HydrateArrayOf $attr */
            $attr = $attrs[0]->newInstance();
            $itemClass = $attr->class;

            $items = [];
            foreach ($raw as $item) {
                $items[] = is_array($item)
                    ? $this->doMake($itemClass, $item, unwrapCloudEvent: false, allowFromPayload: true, allowFrom: true)
                    : $item;
            }
        } else {
            $items = $raw;
        }

        // If Illuminate isn't available, fallback to array
        if (!class_exists($illuminateCollection)) {
            return $items;
        }

        // If helper exists use it, else instantiate
        if (function_exists('collect')) {
            return collect($items);
        }

        return new $illuminateCollection($items);
    }

    // -------------------------------------------------------------------------
    // SERIALIZATION SIDE (object -> array)
    // -------------------------------------------------------------------------

    /**
     * Serialize an object (event/DTO) to plain array, recursively.
     *
     * - Avoids hard dependency on Spatie Data; if object has toArray() method, uses it.
     * - Otherwise:
     *   - Prefer public properties; if none, fall back to constructor params & property access.
     */
    public function toArray(object $object): array
    {
        // If a DTO already knows how to become array, use it.
        if (method_exists($object, 'toArray')) {
            $maybe = $object->toArray();
            if (is_array($maybe)) {
                return $maybe;
            }
        }

        $ref = new ReflectionClass($object);

        // Prefer public properties; if none, fall back to constructor params
        $props = $ref->getProperties(ReflectionProperty::IS_PUBLIC);

        $data = [];

        if (!empty($props)) {
            foreach ($props as $prop) {
                $name = $prop->getName();
                $value = $prop->getValue($object);
                $data[$name] = $this->serializeValue($value);
            }

            return $data;
        }

        // Fallback: use constructor parameter names + public accessors/private props
        $constructor = $ref->getConstructor();

        if (!$constructor) {
            return [];
        }

        foreach ($constructor->getParameters() as $param) {
            $name = $param->getName();

            if ($ref->hasProperty($name)) {
                $prop = $ref->getProperty($name);
                $prop->setAccessible(true);
                $value = $prop->getValue($object);
            } elseif ($ref->hasMethod($name)) {
                $method = $ref->getMethod($name);
                $value = $method->getNumberOfRequiredParameters() === 0
                    ? $method->invoke($object)
                    : null;
            } else {
                $value = null;
            }

            $data[$name] = $this->serializeValue($value);
        }

        return $data;
    }

    protected function serializeValue(mixed $value): mixed
    {
        if ($value === null) {
            return null;
        }

        if (is_scalar($value)) {
            return $value;
        }

        if ($value instanceof BackedEnum) {
            return $value->value;
        }

        if ($value instanceof DateTimeInterface) {
            return $value->format(DateTimeInterface::ATOM);
        }

        // Optional: illuminate collection / iterables
        if (is_iterable($value)) {
            $result = [];
            foreach ($value as $k => $item) {
                $result[$k] = $this->serializeValue($item);
            }
            return $result;
        }

        if (is_array($value)) {
            $result = [];
            foreach ($value as $k => $item) {
                $result[$k] = $this->serializeValue($item);
            }
            return $result;
        }

        if (is_object($value)) {
            // Best effort
            if (method_exists($value, 'toArray')) {
                $maybe = $value->toArray();
                if (is_array($maybe)) {
                    return $maybe;
                }
            }

            return $this->toArray($value);
        }

        return $value;
    }
}
