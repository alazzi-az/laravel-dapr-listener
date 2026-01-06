<?php

namespace AlazziAz\LaravelDaprListener\Consuming;

use AlazziAz\LaravelDapr\Support\IngressContext;
use AlazziAz\LaravelDapr\Support\IngressSignatureVerifier;
use AlazziAz\LaravelDapr\Support\Subscription;
use AlazziAz\LaravelDapr\Support\SubscriptionRegistry;
use AlazziAz\LaravelDaprListener\Support\EventHydrator;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Http\Request;
use Illuminate\Pipeline\Pipeline;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class MessageProcessor
{
    public function __construct(
        protected SubscriptionRegistry $subscriptions,
        protected IngressSignatureVerifier $signature,
        protected EventHydrator $hydrator,
        protected Dispatcher $events,
        protected IngressContext $ingressContext,
        protected Pipeline $pipeline,
        protected Repository $config
    ) {
    }

    public function handle(Request $request, string $routeKey): array
    {
        if (! $this->signature->verify($request)) {
            abort(403, 'Invalid Dapr ingress signature.');
        }

        $subscription = $this->subscriptions->findByRoute($routeKey);

        if (! $subscription) {
            abort(404, "Unknown Dapr topic for route [$routeKey]");
        }

        $raw = $this->decodeRequest($request);
        $payload =  $raw['data'] ?? ($raw['body']['data'] ?? $raw);

        $metadata = $this->extractMetadata($request, $raw);

        $event = $this->hydrator->make($subscription->event, $payload);
        $this->ingressContext->markInbound($event);

        $context = new ListenerContext(
            $subscription,
            $event,
            $payload,
            $metadata,
            $request
        );

        $middleware = $this->config->get('dapr.listener.middleware', []);

        $context = $this->pipeline
            ->send($context)
            ->through($middleware)
            ->then(function (ListenerContext $context) {
                $this->events->dispatch($context->event());

                Log::info('Dispatched Dapr event to Laravel listeners.', [
                    'event_class' => $context->event()::class,
                    'topic' => $context->subscription()->topic,
                    'metadata' => $context->metadata(),
                ]);

                return $context;
            });

        return [
            'status' => 'SUCCESS',
        ];
    }

    protected function decodeRequest(Request $request): array
    {
        $content = $request->getContent();

        if ($content === '') {
            return [];
        }

        $decoded = json_decode($content, true);

        return is_array($decoded) ? $decoded : [];
    }

    protected function extractMetadata(Request $request, array $raw): array
    {
        $metadata = [];

        // 1) Dapr publish metadata (from query metadata.*)
        if (isset($raw['metadata']) && is_array($raw['metadata'])) {
            $metadata = $raw['metadata'];
        }

        // 2) CloudEvent extensions (only if your emitter used CloudEvents extensions explicitly)
        if (isset($raw['extensions']) && is_array($raw['extensions'])) {
            $metadata = array_merge($metadata, $raw['extensions']);
        }

        // 3) Trace context commonly forwarded by Dapr
        foreach (['traceid', 'traceparent', 'tracestate'] as $key) {
            if (!empty($raw[$key])) {
                $metadata[$key] = $raw[$key];
            }
        }

        // 4) Optional: include request headers (often noisy; consider filtering)
        foreach ($request->headers->all() as $key => $values) {
            $metadata['header_' . Str::snake($key)] = implode(',', $values);
        }

        return $metadata;
    }
}
