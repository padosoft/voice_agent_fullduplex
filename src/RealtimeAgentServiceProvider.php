<?php

declare(strict_types=1);

namespace AgentsFullDuplex\RealtimeAgent;

use AgentsFullDuplex\RealtimeAgent\Contracts\EventStoreContract;
use AgentsFullDuplex\RealtimeAgent\Contracts\StateStoreContract;
use AgentsFullDuplex\RealtimeAgent\Contracts\ToolBrokerContract;
use AgentsFullDuplex\RealtimeAgent\Contracts\ToolCallStoreContract;
use AgentsFullDuplex\RealtimeAgent\Engine\AgentSessionManager;
use AgentsFullDuplex\RealtimeAgent\Engine\ToolBroker;
use AgentsFullDuplex\RealtimeAgent\State\ArrayEventStore;
use AgentsFullDuplex\RealtimeAgent\State\ArrayStateStore;
use AgentsFullDuplex\RealtimeAgent\State\DatabaseEventStore;
use AgentsFullDuplex\RealtimeAgent\State\DatabaseStateStore;
use AgentsFullDuplex\RealtimeAgent\State\ArrayToolCallStore;
use AgentsFullDuplex\RealtimeAgent\State\DatabaseToolCallStore;
use Illuminate\Support\ServiceProvider;

final class RealtimeAgentServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/realtime-agent.php', 'realtime-agent');

        $this->app->singleton(StateStoreContract::class, function ($app): StateStoreContract {
            if ($app['config']->get('realtime-agent.state.driver') === 'array') {
                return $app->make(ArrayStateStore::class);
            }

            return new DatabaseStateStore($app['db']->connection());
        });

        $this->app->singleton(EventStoreContract::class, function ($app): EventStoreContract {
            if (
                $app['config']->get('realtime-agent.state.driver') === 'array'
                || ! $app['config']->get('realtime-agent.state.persist_events', true)
            ) {
                return $app->make(ArrayEventStore::class);
            }

            return new DatabaseEventStore($app['db']->connection());
        });

        $this->app->singleton(ToolCallStoreContract::class, function ($app): ToolCallStoreContract {
            if ($app['config']->get('realtime-agent.state.driver') === 'array') {
                return $app->make(ArrayToolCallStore::class);
            }

            return new DatabaseToolCallStore($app['config'], $app['db']->connection());
        });

        $this->app->singleton(ToolBrokerContract::class, ToolBroker::class);
        $this->app->singleton(AgentSessionManager::class);
        $this->app->alias(AgentSessionManager::class, 'realtime-agent');
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');

        $this->publishes([
            __DIR__.'/../config/realtime-agent.php' => config_path('realtime-agent.php'),
        ], 'realtime-agent-config');

        $this->publishes([
            __DIR__.'/../resources/js' => resource_path('js/vendor/realtime-agent'),
        ], 'realtime-agent-assets');
    }
}
