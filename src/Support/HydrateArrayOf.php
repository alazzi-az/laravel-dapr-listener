<?php

namespace AlazziAz\LaravelDaprListener\Support;
use Attribute;

#[Attribute(Attribute::TARGET_PARAMETER)]
class HydrateArrayOf
{
    /**
     * @param class-string $class The DTO class to hydrate each array item into.
     */
    public function __construct(
        public string $class
    ) {}
}
