<?php

use AlazziAz\LaravelDaprListener\Consuming\IngressController;
use Illuminate\Support\Facades\Route;

$prefix = trim(config('dapr.http.prefix', 'dapr'), '/');

Route::prefix($prefix)->group(function () {
    Route::post('/ingress/{topic?}', IngressController::class)
        ->where('topic', '.*')
        ->name('dapr.ingress');
});
