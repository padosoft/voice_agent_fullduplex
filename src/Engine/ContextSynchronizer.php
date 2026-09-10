<?php

declare(strict_types=1);

namespace AgentsFullDuplex\RealtimeAgent\Engine;

use AgentsFullDuplex\RealtimeAgent\Data\AgentSession;
use AgentsFullDuplex\RealtimeAgent\Data\AgentState;

final readonly class ContextSynchronizer
{
    public function __construct(private StateEngine $states) {}

    /** @param array<string, mixed> $snapshot */
    public function snapshot(AgentSession $session, int $baseRevision, array $snapshot): AgentState
    {
        return $this->states->updateSurface($session, $baseRevision, $snapshot);
    }

    /** @param list<array<string, mixed>> $patches */
    public function patch(AgentSession $session, int $baseRevision, array $patches): AgentState
    {
        return $this->states->patchSurface($session, $baseRevision, $patches);
    }

    /** @return array<string, mixed> */
    public function compact(AgentSession $session): array
    {
        $state = $session->state()->toArray();

        return [
            'schema' => $state['schema'],
            'session' => $state['session'],
            'environment' => $state['environment'],
            'mission' => $state['mission'],
            'working_memory' => $state['working_memory'],
            'application' => $state['application'],
        ];
    }
}
