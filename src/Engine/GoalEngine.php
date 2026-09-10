<?php

declare(strict_types=1);

namespace AgentsFullDuplex\RealtimeAgent\Engine;

use AgentsFullDuplex\RealtimeAgent\Contracts\EventStoreContract;
use AgentsFullDuplex\RealtimeAgent\Contracts\FinishPolicyContract;
use AgentsFullDuplex\RealtimeAgent\Contracts\StateStoreContract;
use AgentsFullDuplex\RealtimeAgent\Data\AgentSession;
use AgentsFullDuplex\RealtimeAgent\Data\AgentState;
use AgentsFullDuplex\RealtimeAgent\Engine\Mutations\ReplaceState;
use AgentsFullDuplex\RealtimeAgent\Enums\GoalStatus;
use AgentsFullDuplex\RealtimeAgent\Exceptions\ToolCallRejected;
use AgentsFullDuplex\RealtimeAgent\Events\AgentGoalCompleted;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Events\Dispatcher;

final readonly class GoalEngine
{
    public function __construct(
        private StateStoreContract $states,
        private EventStoreContract $events,
        private Container $container,
        private Dispatcher $dispatcher,
    ) {
    }

    public function update(
        AgentSession $session,
        int $baseRevision,
        string $goalId,
        GoalStatus $status,
        ?string $evidence = null,
    ): AgentState {
        $policy = $this->container->make($session->definition->finishPolicy);

        if (! $policy instanceof FinishPolicyContract) {
            throw new \LogicException('Finish policy must implement '.FinishPolicyContract::class.'.');
        }

        $next = $this->states->mutate(
            $session->id,
            $baseRevision,
            new ReplaceState(static function (AgentState $current) use ($goalId, $status, $evidence, $policy): array {
                $state = $current->toArray();
                $found = false;

                foreach ($state['mission']['goals'] as &$goal) {
                    if ($goal['id'] !== $goalId) {
                        continue;
                    }

                    $found = true;

                    if (
                        $status === GoalStatus::Completed
                        && ($goal['completion']['requires_evidence'] ?? false)
                        && blank($evidence)
                    ) {
                        throw new ToolCallRejected("Goal {$goalId} requires completion evidence.");
                    }

                    $goal['status'] = $status->value;
                    $goal['evidence'] = $evidence;
                    $goal['completed_at'] = $status === GoalStatus::Completed
                        ? now()->toISOString()
                        : null;
                }
                unset($goal);

                if (! $found) {
                    throw new ToolCallRejected("Unknown goal: {$goalId}.");
                }

                $state['working_memory']['last_action'] = [
                    'type' => 'goal.'.$status->value,
                    'goal_id' => $goalId,
                ];

                $candidate = AgentState::fromArray($state);

                if ($policy->shouldFinish($candidate)) {
                    $state['session']['status'] = 'completed';
                    $state['session']['ended_at'] = now()->toISOString();
                }

                return $state;
            }),
        );

        $eventType = $status === GoalStatus::Completed ? 'goal.completed' : 'goal.updated';
        $this->events->append(
            $session->id,
            $eventType,
            'engine',
            $next->revision(),
            ['goal_id' => $goalId, 'status' => $status->value, 'evidence' => $evidence],
        );
        $this->events->append(
            $session->id,
            'state.changed',
            'engine',
            $next->revision(),
            ['path' => '/mission/goals'],
        );

        if ($next->status() === 'completed') {
            $this->events->append($session->id, 'session.completed', 'engine', $next->revision());
        }

        if ($status === GoalStatus::Completed) {
            $this->dispatcher->dispatch(new AgentGoalCompleted(
                sessionId: $session->id,
                goalId: $goalId,
                evidence: $evidence,
                state: $next,
            ));
        }

        return $next;
    }
}
