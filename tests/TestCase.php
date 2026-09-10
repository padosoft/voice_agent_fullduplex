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
        $app['config']->set('realtime-agent.default', 'fake');
        $app['config']->set('realtime-agent.state.driver', 'array');
        $app['config']->set('realtime-agent.state.persist_events', true);
    }
}
