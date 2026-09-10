<?php

declare(strict_types=1);

namespace AgentsFullDuplex\RealtimeAgent\Providers\Fake;

use AgentsFullDuplex\RealtimeAgent\Contracts\EventStoreContract;
use AgentsFullDuplex\RealtimeAgent\Contracts\RealtimeProviderContract;
use AgentsFullDuplex\RealtimeAgent\Data\AgentDefinition;
use AgentsFullDuplex\RealtimeAgent\Data\AgentSession;
use AgentsFullDuplex\RealtimeAgent\Data\ClientConnectionDescriptor;
use AgentsFullDuplex\RealtimeAgent\Data\ProviderCapabilities;
use AgentsFullDuplex\RealtimeAgent\Data\ProviderSession;
use AgentsFullDuplex\RealtimeAgent\Data\ProviderToolSet;
use AgentsFullDuplex\RealtimeAgent\Data\ToolCall;
use AgentsFullDuplex\RealtimeAgent\Data\ToolResult;

final class FakeRealtimeProvider implements RealtimeProviderContract
{
    public function __construct(private readonly EventStoreContract $events) {}

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

    public function connect(AgentSession $session, ?string $offer = null): ClientConnectionDescriptor
    {
        return $this->clientConnection($session);
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

    /**
     * Run a deterministic provider-side script without network access.
     *
     * @param  iterable<array<string, mixed>>  $steps
     * @return list<ToolResult|null>
     */
    public function simulate(AgentSession $session, iterable $steps): array
    {
        $results = [];

        foreach ($steps as $index => $step) {
            if (isset($step['transcript'])) {
                $this->events->append(
                    $session->id,
                    'agent.transcript.final',
                    'provider',
                    $session->state()->revision(),
                    ['text' => (string) $step['transcript']],
                );
                $results[] = null;

                continue;
            }

            $name = (string) ($step['tool'] ?? '');
            $callId = 'fake_call_'.($index + 1);
            $call = new ToolCall(
                id: $callId,
                name: $name,
                arguments: (array) ($step['arguments'] ?? []),
                baseRevision: $session->state()->revision(),
                idempotencyKey: (string) ($step['idempotency_key'] ?? $callId),
                providerCallId: $callId,
            );
            $this->events->append(
                $session->id,
                'agent.tool.call',
                'provider',
                $session->state()->revision(),
                ['call' => $call->toArray()],
            );
            $results[] = $session->execute($call);
        }

        return $results;
    }
}
