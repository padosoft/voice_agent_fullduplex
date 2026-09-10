<?php

declare(strict_types=1);

namespace AgentsFullDuplex\RealtimeAgent\Providers\ElevenLabs;

use AgentsFullDuplex\RealtimeAgent\Contracts\RealtimeProviderContract;
use AgentsFullDuplex\RealtimeAgent\Data\AgentDefinition;
use AgentsFullDuplex\RealtimeAgent\Data\AgentSession;
use AgentsFullDuplex\RealtimeAgent\Data\ClientConnectionDescriptor;
use AgentsFullDuplex\RealtimeAgent\Data\ProviderCapabilities;
use AgentsFullDuplex\RealtimeAgent\Data\ProviderSession;
use AgentsFullDuplex\RealtimeAgent\Data\ProviderToolSet;

final class ElevenLabsProvider implements RealtimeProviderContract
{
    public function capabilities(): ProviderCapabilities
    {
        return new ProviderCapabilities(true, false, true, false, true, true, false);
    }

    public function prepare(AgentSession $session, AgentDefinition $definition): ProviderSession
    {
        throw $this->notImplemented();
    }

    public function clientConnection(AgentSession $session): ClientConnectionDescriptor
    {
        throw $this->notImplemented();
    }

    public function materializeTools(AgentSession $session, iterable $tools): ProviderToolSet
    {
        throw $this->notImplemented();
    }

    private function notImplemented(): \LogicException
    {
        return new \LogicException(
            'The ElevenLabs transport is a Phase 4 extension point. Use the fake provider to exercise the canonical MVP.',
        );
    }
}
