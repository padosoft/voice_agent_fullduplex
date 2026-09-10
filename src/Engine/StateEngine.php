<?php

declare(strict_types=1);

namespace AgentsFullDuplex\RealtimeAgent\Engine;

use AgentsFullDuplex\RealtimeAgent\Contracts\EventStoreContract;
use AgentsFullDuplex\RealtimeAgent\Contracts\StateStoreContract;
use AgentsFullDuplex\RealtimeAgent\Data\AgentSession;
use AgentsFullDuplex\RealtimeAgent\Data\AgentState;
use AgentsFullDuplex\RealtimeAgent\Data\UiCommand;
use AgentsFullDuplex\RealtimeAgent\Engine\Mutations\ReplaceState;
use AgentsFullDuplex\RealtimeAgent\Events\AgentStateChanged;
use Illuminate\Contracts\Events\Dispatcher;

final readonly class StateEngine
{
    public function __construct(
        private StateStoreContract $states,
        private EventStoreContract $events,
        private Dispatcher $dispatcher,
    ) {
    }

    /** @param array<string, mixed> $update */
    public function updateWorkingMemory(AgentSession $session, int $baseRevision, array $update): AgentState
    {
        $next = $this->states->mutate(
            $session->id,
            $baseRevision,
            new ReplaceState(static function (AgentState $current) use ($update): array {
                $state = $current->toArray();
                $memory = $state['working_memory'];

                foreach (['summary', 'current_step'] as $field) {
                    if (array_key_exists($field, $update)) {
                        $memory[$field] = $update[$field];
                    }
                }

                foreach (['facts', 'decisions', 'completed_steps', 'tool_outputs', 'notes'] as $field) {
                    $addKey = $field.'_add';

                    if (isset($update[$addKey])) {
                        $memory[$field] = [...($memory[$field] ?? []), ...$update[$addKey]];
                    }
                }

                $state['working_memory'] = $memory;
                $state['working_memory']['last_action'] = ['type' => 'working_memory.updated'];

                return $state;
            }),
        );

        $this->recordStateChange($session->id, $next, ['path' => '/working_memory']);

        return $next;
    }

    /** @param array<string, mixed> $snapshot */
    public function updateSurface(AgentSession $session, int $baseRevision, array $snapshot): AgentState
    {
        $next = $this->states->mutate(
            $session->id,
            $baseRevision,
            new ReplaceState(static function (AgentState $current) use ($snapshot): array {
                $state = $current->toArray();
                $currentUiRevision = (int) ($state['environment']['ui']['revision'] ?? 0);
                $state['environment']['ui'] = $snapshot;
                $state['environment']['ui']['revision'] = $currentUiRevision + 1;

                return $state;
            }),
        );

        $this->events->append(
            $session->id,
            'ui.snapshot',
            'client',
            $next->revision(),
            ['surface' => $snapshot['surface'] ?? null],
        );
        $this->recordStateChange($session->id, $next, ['path' => '/environment/ui']);

        return $next;
    }

    public function finish(AgentSession $session, int $baseRevision): AgentState
    {
        $next = $this->states->mutate(
            $session->id,
            $baseRevision,
            new ReplaceState(static function (AgentState $current): array {
                $state = $current->toArray();
                $state['session']['status'] = 'completed';
                $state['session']['ended_at'] = now()->toISOString();

                return $state;
            }),
        );

        $this->recordStateChange($session->id, $next, ['path' => '/session/status']);
        $this->events->append($session->id, 'session.completed', 'engine', $next->revision());

        return $next;
    }

    public function queueUiCommand(AgentSession $session, int $baseRevision, UiCommand $command): AgentState
    {
        $next = $this->states->mutate(
            $session->id,
            $baseRevision,
            new ReplaceState(static function (AgentState $current) use ($command): array {
                $state = $current->toArray();
                $state['pending']['actions'][] = $command->jsonSerialize();

                return $state;
            }),
        );

        $this->events->append(
            $session->id,
            'ui.command.requested',
            'engine',
            $next->revision(),
            ['command' => $command->jsonSerialize()],
        );
        $this->recordStateChange($session->id, $next, ['path' => '/pending/actions']);

        return $next;
    }

    /** @param array<string, mixed> $payload */
    private function recordStateChange(string $sessionId, AgentState $state, array $payload): void
    {
        $this->events->append(
            $sessionId,
            'state.changed',
            'engine',
            $state->revision(),
            $payload,
        );
        $this->dispatcher->dispatch(new AgentStateChanged(
            sessionId: $sessionId,
            state: $state,
            path: (string) ($payload['path'] ?? '/'),
        ));
    }
}
