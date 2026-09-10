<?php

declare(strict_types=1);

namespace AgentsFullDuplex\RealtimeAgent\Tests;

use AgentsFullDuplex\RealtimeAgent\RealtimeAgentServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [RealtimeAgentServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('app.key', 'base64:'.base64_encode(str_repeat('r', 32)));
        $app['config']->set('cache.default', 'array');
        $app['config']->set('realtime-agent.default', 'fake');
        $app['config']->set('realtime-agent.state.driver', 'array');
        $app['config']->set('realtime-agent.state.persist_events', true);
        $app['config']->set('realtime-agent.routes.middleware', []);
        $app['config']->set('realtime-agent.security.require_authorization', false);
    }

    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
    }
}
