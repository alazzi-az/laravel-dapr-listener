<?php

return [
    'listener' => [
        'middleware' => [
            \AlazziAz\LaravelDaprListener\Middleware\RetryOnceMiddleware::class,
            \AlazziAz\LaravelDaprListener\Middleware\CorrelatedMessageMiddleware::class,
            \AlazziAz\LaravelDaprListener\Middleware\TenantHydratorMiddleware::class,
        ],
    ],
];
