<?php

use AlazziAz\LaravelDaprListener\Middleware\CorrelatedMessageMiddleware;
use AlazziAz\LaravelDaprListener\Middleware\RetryOnceMiddleware;
use AlazziAz\LaravelDaprListener\Middleware\TenantHydratorMiddleware;

return [
    'listener' => [
        'concurrency' => 1,
        'middleware' => [
            RetryOnceMiddleware::class,
            CorrelatedMessageMiddleware::class,
            TenantHydratorMiddleware::class,
        ],
        'discovery' => [
            'enabled' => true,

            // enable/disable each discovery channel
            // so this will register events topics to dapr subscriptions so the event will be dispatched in this service also
            'events' => [
                'enabled' => false,
                'directories' => [
                    app_path('Events'),
                    // base_path('modules/*/Events'),
                ],
            ],

            'listeners' => [
                'enabled' => true,
                'directories' => [
                    app_path('Listeners'),
                    // base_path('modules/*/Listeners'),
                ],
            ],
        ]
    ],
];
