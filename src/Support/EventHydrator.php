<?php

namespace AlazziAz\LaravelDaprListener\Support;

use Carbon\Carbon;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use ReflectionAttribute;
use ReflectionClass;
use ReflectionNamedType;
use ReflectionParameter;
use ReflectionProperty;
use BackedEnum;
use DateTimeInterface;
use InvalidArgumentException;

class EventHydrator
{
    /**
     * Entry point to hydrate an event from a Dapr/CloudEvent-like payload.
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
     * @param  class-string  $class
     */
    protected function doMake(
        string $class,
        array $payload,
        bool $unwrapCloudEvent = false,
        bool $allowFromPayload = false,
        bool $allowFrom=false,

    ): object {
        if ($allowFromPayload && method_exists($class, 'fromPayload')) {
            return $class::fromPayload($payload);
        }
        if ($allowFrom && method_exists($class, 'from')) {
            return $class::from($payload);
        }


        if ($unwrapCloudEvent) {
            logger()->info('payloadBefore:', ['payload' => $payload]);

            $payload = $this->unwrapCloudEvent($payload);
            $payload = $payload['data'] ?? $payload;

            logger()->info('payload:', ['payload' => $payload]);
        }

        $reflection = new ReflectionClass($class);

        if (! $reflection->isInstantiable()) {
            throw new InvalidArgumentException("Event class [$class] is not instantiable.");
        }

        $constructor = $reflection->getConstructor();

        if (! $constructor) {
            return new $class();
        }

        $flattened = Arr::dot($payload);

        logger()->info('flattened:', ['flattened' => $flattened]);

        $arguments = [];

        foreach ($constructor->getParameters() as $parameter) {
            $paramName = $parameter->getName();
            $paramType = $parameter->getType();

            $rawValue = $this->resolveValue(
                original: $payload,
                flattened: $flattened,
                parameterName: $paramName
            );

            $value = $this->castParameterValue($parameter, $rawValue, $unwrapCloudEvent);

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

    protected function unwrapCloudEvent(array $payload): array
    {
        if (isset($payload['specversion'], $payload['data']) && is_array($payload['data'])) {
            return $payload['data'];
        }

        return $payload;
    }

    /**
     * Resolve value for a given constructor parameter name.
     *
     * - First: try direct / nested access on the original payload (so `userData`
     *   will receive the whole nested array).
     * - Then: fallback to flattened last-segment matching for scalar fields
     *   like `email`, `id`, etc.
     */
    protected function resolveValue(array $original, array $flattened, string $parameterName): mixed
    {
        $candidates = [
            $parameterName,
            Str::snake($parameterName),
            Str::camel($parameterName),
            Str::studly($parameterName),
        ];

        // 1) Try direct key on original payload
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
            $lastSegment = Str::afterLast($key, '.');

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
     */
    protected function castParameterValue(ReflectionParameter $parameter, mixed $raw, bool $unwrapCloudEvent): mixed
    {
        $type = $parameter->getType();

        if (! $type instanceof ReflectionNamedType) {
            return $raw;
        }

        if ($raw === null) {
            return null;
        }

        if ($type->isBuiltin()) {
            $name = $type->getName();

            if ($name === 'array') {
                return $this->castArrayParameter($parameter, $raw, $unwrapCloudEvent);
            }

            return $this->castBuiltin($name, $raw);
        }

        // Non-builtin class: DateTime, Enum, DTO...
        $className = $type->getName();

        // Backed enum
        if (enum_exists($className) && is_subclass_of($className, BackedEnum::class)) {
            /** @var class-string<BackedEnum> $className */
            return $className::from($raw);
        }

        // Date / DateTime
        if (is_a($className, DateTimeInterface::class, true)) {
            return Carbon::parse($raw);
        }

        // Nested DTO
        if (is_array($raw)) {
            return $this->doMake(
                class: $className,
                payload: $raw,
                unwrapCloudEvent: false,
                allowFromPayload: true
            );
        }

        // If it's some other object class but raw is not array, just return raw
        return $raw;
    }

    /**
     * Handle "array" parameters, with support for arrays of DTOs using #[HydrateArrayOf].
     */
    protected function castArrayParameter(ReflectionParameter $parameter, mixed $raw, bool $unwrapCloudEvent): mixed
    {
        if (! is_array($raw)) {
            return (array) $raw;
        }

        $attributes = $parameter->getAttributes(HydrateArrayOf::class, ReflectionAttribute::IS_INSTANCEOF);

        if (empty($attributes)) {
            // regular array (scalar or associative)
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
                    allowFromPayload: true
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
            'int'     => (int) $value,
            'float'   => (float) $value,
            'string'  => (string) $value,
            'bool'    => (bool) $value,
            'array'   => (array) $value,
            default   => $value,
        };
    }

    // -------------------------------------------------------------------------
    // SERIALIZATION SIDE (object -> array)
    // -------------------------------------------------------------------------

    /**
     * Serialize an object (event/DTO) to plain array, recursively.
     */
    public function toArray(object $object): array
    {
        $ref = new ReflectionClass($object);

        // Prefer public properties; if none, fall back to constructor params
        $props = $ref->getProperties(ReflectionProperty::IS_PUBLIC);

        $data = [];

        if (! empty($props)) {
            foreach ($props as $prop) {
                $name = $prop->getName();
                $value = $prop->getValue($object);
                $data[$name] = $this->serializeValue($value);
            }

            return $data;
        }

        // Fallback: use constructor parameter names + public accessors
        $constructor = $ref->getConstructor();

        if (! $constructor) {
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
                if ($method->getNumberOfRequiredParameters() === 0) {
                    $value = $method->invoke($object);
                } else {
                    $value = null;
                }
            } else {
                $value = null;
            }

            $data[$name] = $this->serializeValue($value);
        }

        return $data;
    }

    protected function serializeValue(mixed $value): mixed
    {
        if (is_null($value)) {
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

        if (is_array($value)) {
            $result = [];
            foreach ($value as $key => $item) {
                $result[$key] = $this->serializeValue($item);
            }

            return $result;
        }

        if (is_iterable($value) && ! is_array($value)) {
            $result = [];
            foreach ($value as $key => $item) {
                $result[$key] = $this->serializeValue($item);
            }

            return $result;
        }

        if (is_object($value)) {
            return $this->toArray($value);
        }

        return $value;
    }
}
