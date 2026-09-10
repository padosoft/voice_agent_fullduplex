<?php

declare(strict_types=1);

namespace AgentsFullDuplex\RealtimeAgent\Engine;

use AgentsFullDuplex\RealtimeAgent\Contracts\EventStoreContract;
use AgentsFullDuplex\RealtimeAgent\Contracts\StateStoreContract;
use AgentsFullDuplex\RealtimeAgent\Contracts\ToolBrokerContract;
use AgentsFullDuplex\RealtimeAgent\Data\AgentDefinition;
use AgentsFullDuplex\RealtimeAgent\Data\AgentSession;
use AgentsFullDuplex\RealtimeAgent\Events\AgentSessionStarted;
use AgentsFullDuplex\RealtimeAgent\Providers\ProviderManager;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\Str;

final readonly class AgentSessionManager
{
    public function __construct(
        private Config $config,
        private StateStoreContract $states,
        private EventStoreContract $events,
        private ToolBrokerContract $tools,
        private ToolRegistry $registry,
        private InitialStateFactory $stateFactory,
        private ProviderManager $providers,
        private Dispatcher $dispatcher,
    ) {
    }

    public function make(string $key): AgentDefinitionBuilder
    {
        return new AgentDefinitionBuilder(
            manager: $this,
            key: $key,
            defaultProvider: (string) $this->config->get('realtime-agent.default', 'fake'),
        );
    }

    public function start(AgentDefinition $definition, mixed $owner): AgentSession
    {
        $sessionId = (string) Str::ulid();
        $startedAt = now()->toISOString();
        $state = $this->stateFactory->make($sessionId, $definition, $startedAt);
        $this->states->create($state, $owner);

        $session = new AgentSession(
            id: $sessionId,
            definition: $definition,
            owner: $owner,
            states: $this->states,
            tools: $this->tools,
        );

        $provider = $this->providers->driver($definition->provider);
        $provider->materializeTools($session, $this->registry->forDefinition($definition));
        $provider->prepare($session, $definition);
        $session->setConnection($provider->clientConnection($session));

        $this->events->append(
            $sessionId,
            'session.start',
            'application',
            $state->revision(),
            ['agent' => $definition->key, 'provider' => $definition->provider],
        );
        $this->dispatcher->dispatch(new AgentSessionStarted($session));

        return $session;
    }
}
