<?php

namespace AlazziAz\LaravelDaprListener\Consuming;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class IngressController
{
    public function __construct(
        protected MessageProcessor $processor
    ) {
    }

    public function __invoke(Request $request, ?string $topic = null): JsonResponse
    {
        logger()->info('Dapr Ingress received request', [
            'path' => $request->path(),
            'topic' => $topic,
            'body' => $request->all(),
            'headers' => $request->headers->all(),
            'query' => $request->query->all(),
            'method' => $request->method(),
            'ip' => $request->ip(),
        ]);
        $prefix = trim(config('dapr.http.prefix', 'dapr'), '/');
        $topic = $topic ? trim($topic, '/') : '';
        $route = $prefix.'/ingress'.($topic ? '/'.$topic : '');

        $result = $this->processor->handle($request, $route);

        return new JsonResponse($result);
    }
}
