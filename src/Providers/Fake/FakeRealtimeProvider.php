<?php

declare(strict_types=1);

namespace AgentsFullDuplex\RealtimeAgent\Providers\Fake;

use AgentsFullDuplex\RealtimeAgent\Contracts\RealtimeProviderContract;
use AgentsFullDuplex\RealtimeAgent\Data\AgentDefinition;
use AgentsFullDuplex\RealtimeAgent\Data\AgentSession;
use AgentsFullDuplex\RealtimeAgent\Data\ClientConnectionDescriptor;
use AgentsFullDuplex\RealtimeAgent\Data\ProviderCapabilities;
use AgentsFullDuplex\RealtimeAgent\Data\ProviderSession;
use AgentsFullDuplex\RealtimeAgent\Data\ProviderToolSet;

final class FakeRealtimeProvider implements RealtimeProviderContract
{
    public function capabilities(): ProviderCapabilities
    {
        return new ProviderCapabilities(
            audioFullDuplex: false,
            clientWebRtc: false,
            clientWebSocket: false,
            dynamicInlineTools: true,
            nativeContextUpdates: true,
            clientTools: true,
            serverSideControl: true,
        );
    }

    public function prepare(AgentSession $session, AgentDefinition $definition): ProviderSession
    {
        return new ProviderSession('fake_'.$session->id, ['deterministic' => true]);
    }

    public function clientConnection(AgentSession $session): ClientConnectionDescriptor
    {
        return new ClientConnectionDescriptor(
            sessionId: $session->id,
            provider: 'fake',
            connection: ['transport' => 'fake'],
            state: $session->state(),
        );
    }

    public function materializeTools(AgentSession $session, iterable $tools): ProviderToolSet
    {
        $materialized = [];

        foreach ($tools as $tool) {
            $materialized[] = [
                'type' => 'function',
                'name' => $tool->name(),
                'schema' => $tool->schema(),
            ];
        }

        return new ProviderToolSet($materialized);
    }
}
