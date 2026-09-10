<?php

declare(strict_types=1);

namespace AgentsFullDuplex\RealtimeAgent\State;

use AgentsFullDuplex\RealtimeAgent\Contracts\StateMutation;
use AgentsFullDuplex\RealtimeAgent\Contracts\StateStoreContract;
use AgentsFullDuplex\RealtimeAgent\Data\AgentDefinition;
use AgentsFullDuplex\RealtimeAgent\Data\AgentState;
use AgentsFullDuplex\RealtimeAgent\Exceptions\RevisionConflict;
use AgentsFullDuplex\RealtimeAgent\Models\AgentSessionRecord;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Eloquent\Model;

final readonly class DatabaseStateStore implements StateStoreContract
{
    public function __construct(private ConnectionInterface $database) {}

    public function create(AgentState $state, mixed $owner, AgentDefinition $definition): AgentState
    {
        $session = $state->toArray()['session'];

        $attributes = [
            'id' => $state->sessionId(),
            'agent_key' => $session['agent'],
            'provider' => $session['provider'],
            'status' => $session['status'],
            'state' => $state->toArray(),
            'state_revision' => $state->revision(),
            'started_at' => $session['started_at'],
            'definition' => $definition->toArray(),
            'event_sequence' => 0,
        ];

        if ($owner instanceof Model) {
            $attributes['owner_type'] = $owner->getMorphClass();
            $attributes['owner_id'] = $owner->getKey();
        }

        AgentSessionRecord::query()->create($attributes);

        return $state;
    }

    public function get(string $sessionId): AgentState
    {
        $record = AgentSessionRecord::query()->findOrFail($sessionId);

        return AgentState::fromArray($record->state);
    }

    public function definition(string $sessionId): AgentDefinition
    {
        $definition = AgentSessionRecord::query()->findOrFail($sessionId)->definition;

        if (! is_array($definition)) {
            throw new \LogicException("Session {$sessionId} has no persisted definition.");
        }

        return AgentDefinition::fromArray($definition);
    }

    public function owner(string $sessionId): mixed
    {
        return AgentSessionRecord::query()->findOrFail($sessionId)->owner;
    }

    public function mutate(string $sessionId, int $baseRevision, StateMutation $mutation): AgentState
    {
        return $this->database->transaction(function () use ($sessionId, $baseRevision, $mutation): AgentState {
            /** @var AgentSessionRecord $record */
            $record = AgentSessionRecord::query()->lockForUpdate()->findOrFail($sessionId);

            if ((int) $record->state_revision !== $baseRevision) {
                throw RevisionConflict::between($baseRevision, (int) $record->state_revision);
            }

            $current = AgentState::fromArray($record->state);
            $next = $mutation->apply($current);
            $currentSession = $current->toArray()['session'];
            $next['session'] = array_replace($currentSession, $next['session'] ?? []);

            foreach (['id', 'agent', 'provider', 'started_at'] as $engineOwnedField) {
                $next['session'][$engineOwnedField] = $currentSession[$engineOwnedField];
            }

            $next['session']['revision'] = $current->revision() + 1;

            $record->forceFill([
                'state' => $next,
                'state_revision' => $next['session']['revision'],
                'status' => $next['session']['status'],
                'ended_at' => $next['session']['ended_at'] ?? null,
            ])->save();

            return AgentState::fromArray($next);
        });
    }
}
