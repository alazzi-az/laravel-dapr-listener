<?php

namespace AlazziAz\LaravelDaprListener;

use AlazziAz\LaravelDapr\Support\SubscriptionRegistry;
use AlazziAz\LaravelDaprListener\Console\MakeListenerCommand;
use AlazziAz\LaravelDaprListener\Consuming\SubscriptionDiscovery;
use AlazziAz\LaravelDaprListener\Support\EventHydrator;
use Illuminate\Support\ServiceProvider as BaseServiceProvider;

class ServiceProvider extends BaseServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/dapr-events.php', 'dapr');

        $this->app->singleton(EventHydrator::class);
        $this->app->singleton(SubscriptionDiscovery::class);
    }

    public function boot(): void
    {
        $this->loadRoutesFrom(__DIR__.'/../routes/dapr.php');

        $this->app->resolving(SubscriptionRegistry::class, function (SubscriptionRegistry $registry, $app) {
            $app->make(SubscriptionDiscovery::class)->discover();
        });

        if ($this->app->runningInConsole()) {
            $this->commands([
                MakeListenerCommand::class,
            ]);

            $this->publishes([
                __DIR__.'/../config/dapr.php' => config_path('dapr.php'),
            ], 'dapr-config');
        }
    }
}
