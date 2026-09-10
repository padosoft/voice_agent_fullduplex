<?php

declare(strict_types=1);

namespace AgentsFullDuplex\RealtimeAgent\State;

use AgentsFullDuplex\RealtimeAgent\Contracts\StateMutation;
use AgentsFullDuplex\RealtimeAgent\Contracts\StateStoreContract;
use AgentsFullDuplex\RealtimeAgent\Data\AgentState;
use AgentsFullDuplex\RealtimeAgent\Exceptions\RealtimeAgentException;
use AgentsFullDuplex\RealtimeAgent\Exceptions\RevisionConflict;

final class ArrayStateStore implements StateStoreContract
{
    /** @var array<string, AgentState> */
    private array $states = [];

    public function create(AgentState $state, mixed $owner = null): AgentState
    {
        if (isset($this->states[$state->sessionId()])) {
            throw new RealtimeAgentException("Session {$state->sessionId()} already exists.");
        }

        return $this->states[$state->sessionId()] = $state;
    }

    public function get(string $sessionId): AgentState
    {
        return $this->states[$sessionId]
            ?? throw new RealtimeAgentException("Session {$sessionId} does not exist.");
    }

    public function mutate(string $sessionId, int $baseRevision, StateMutation $mutation): AgentState
    {
        $current = $this->get($sessionId);

        if ($current->revision() !== $baseRevision) {
            throw RevisionConflict::between($baseRevision, $current->revision());
        }

        $next = $mutation->apply($current);
        $currentSession = $current->toArray()['session'];
        $next['session'] = array_replace($currentSession, $next['session'] ?? []);

        foreach (['id', 'agent', 'provider', 'started_at'] as $engineOwnedField) {
            $next['session'][$engineOwnedField] = $currentSession[$engineOwnedField];
        }

        $next['session']['revision'] = $current->revision() + 1;

        return $this->states[$sessionId] = AgentState::fromArray($next);
    }
}
