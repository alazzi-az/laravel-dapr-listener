<?php

namespace AlazziAz\LaravelDaprListener\Middleware;

use AlazziAz\LaravelDaprListener\Consuming\ListenerContext;
use Closure;

interface ListenerMiddleware
{
    public function handle(ListenerContext $context, Closure $next): mixed;
}
