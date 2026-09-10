<?php

declare(strict_types=1);

namespace AgentsFullDuplex\RealtimeAgent\Providers;

use AgentsFullDuplex\RealtimeAgent\Contracts\RealtimeProviderContract;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Contracts\Container\Container;

final readonly class ProviderManager
{
    public function __construct(
        private Container $container,
        private Config $config,
    ) {}

    public function driver(string $name): RealtimeProviderContract
    {
        $class = $this->config->get("realtime-agent.providers.{$name}.driver");

        if (! is_string($class) || $class === '') {
            throw new \InvalidArgumentException("Realtime agent provider [{$name}] is not configured.");
        }

        $provider = $this->container->make($class);

        if (! $provider instanceof RealtimeProviderContract) {
            throw new \LogicException("Provider [{$name}] must implement ".RealtimeProviderContract::class.'.');
        }

        return $provider;
    }
}
