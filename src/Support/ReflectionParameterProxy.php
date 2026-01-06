<?php

namespace AlazziAz\LaravelDaprListener\Support;
use ReflectionParameter;
use ReflectionNamedType;
use ReflectionType;
use ReturnTypeWillChange;

class ReflectionParameterProxy extends ReflectionParameter
{
    private ReflectionParameter $inner;
    private ReflectionNamedType $overrideType;

    public function __construct(ReflectionParameter $inner, ReflectionNamedType $overrideType)
    {
        // ReflectionParameter constructor expects [class, method] or [function, param]
        // We cannot call parent::__construct safely; instead, we store $inner and expose needed methods.
        // NOTE: We will NOT rely on inherited internals, only our overrides.
        $this->inner = $inner;
        $this->overrideType = $overrideType;

    }

    public function getName(): string
    {
        return $this->inner->getName();
    }

    #[ReturnTypeWillChange]
    public function getType(): ?ReflectionType {

        return $this->overrideType;
    }


    public function allowsNull(): bool
    {
        return $this->overrideType->allowsNull();
    }

    public function isDefaultValueAvailable(): bool
    {
        return $this->inner->isDefaultValueAvailable();
    }

    public function getDefaultValue(): mixed
    {
        return $this->inner->getDefaultValue();
    }

    public function getAttributes(?string $name = null, int $flags = 0): array
    {
        return $this->inner->getAttributes($name, $flags);
    }
}
