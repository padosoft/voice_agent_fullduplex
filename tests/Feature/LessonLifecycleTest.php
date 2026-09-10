<?php

declare(strict_types=1);

namespace AgentsFullDuplex\RealtimeAgent\Tests\Feature;

use AgentsFullDuplex\RealtimeAgent\Contracts\EventStoreContract;
use AgentsFullDuplex\RealtimeAgent\Data\ToolCall;
use AgentsFullDuplex\RealtimeAgent\Engine\AgentSessionManager;
use AgentsFullDuplex\RealtimeAgent\Enums\InteractionLevel;
use AgentsFullDuplex\RealtimeAgent\Exceptions\RevisionConflict;
use AgentsFullDuplex\RealtimeAgent\Goal;
use AgentsFullDuplex\RealtimeAgent\Tests\TestCase;
use AgentsFullDuplex\RealtimeAgent\Tools\RuntimeStateGet;
use AgentsFullDuplex\RealtimeAgent\Tools\UpdateGoal;
use AgentsFullDuplex\RealtimeAgent\Tools\UpdateWorkingMemory;

final class LessonLifecycleTest extends TestCase
{
    public function test_fake_agent_completes_required_goals_and_finishes_session(): void
    {
        $session = $this->lessonSession();

        self::assertSame(1, $session->state()->revision());
        self::assertSame('fake', $session->connection()->provider);
        self::assertSame('active', $session->state()->status());

        foreach (['topic_1', 'topic_2', 'topic_3'] as $index => $goalId) {
            $result = $session->execute(new ToolCall(
                id: 'call_'.$goalId,
                name: 'goal.update',
                arguments: [
                    'goal_id' => $goalId,
                    'status' => 'completed',
                    'evidence' => "Evidence for {$goalId}",
                ],
                baseRevision: $session->state()->revision(),
                idempotencyKey: 'key_'.$goalId,
            ));

            self::assertSame($index + 2, $result->stateRevision);
        }

        self::assertSame('completed', $session->state()->status());
        self::assertSame(4, $session->state()->revision());
        self::assertNotNull($session->state()->toArray()['session']['ended_at']);

        $types = array_map(
            static fn ($event): string => $event->type,
            $this->app->make(EventStoreContract::class)->forSession($session->id),
        );

        self::assertContains('goal.completed', $types);
        self::assertContains('session.completed', $types);
        self::assertSame('session.start', $types[0]);
    }

    public function test_tool_calls_are_idempotent_and_revisions_are_optimistic(): void
    {
        $session = $this->lessonSession();
        $call = new ToolCall(
            id: 'call_1',
            name: 'goal.update',
            arguments: [
                'goal_id' => 'topic_1',
                'status' => 'completed',
                'evidence' => 'A correct explanation.',
            ],
            baseRevision: 1,
            idempotencyKey: 'stable-key',
        );

        $first = $session->execute($call);
        $second = $session->execute($call);

        self::assertSame($first, $second);
        self::assertSame(2, $session->state()->revision());

        $this->expectException(RevisionConflict::class);
        $session->execute(new ToolCall(
            id: 'call_stale',
            name: 'goal.update',
            arguments: [
                'goal_id' => 'topic_2',
                'status' => 'completed',
                'evidence' => 'Stale evidence.',
            ],
            baseRevision: 1,
            idempotencyKey: 'other-key',
        ));
    }

    public function test_working_memory_is_explicit_and_auditable(): void
    {
        $session = $this->lessonSession();
        $result = $session->execute(new ToolCall(
            id: 'memory_1',
            name: 'working_memory.update',
            arguments: [
                'summary' => 'The student understands chlorophyll.',
                'facts_add' => [['key' => 'pace', 'value' => 'steady']],
                'current_step' => 'Discuss the light-dependent phase.',
            ],
            baseRevision: 1,
            idempotencyKey: 'memory-key',
        ));

        self::assertSame('completed', $result->status);
        self::assertSame(
            'The student understands chlorophyll.',
            $session->state()->workingMemory()['summary'],
        );
        self::assertSame(2, $session->state()->revision());
    }

    private function lessonSession(): \AgentsFullDuplex\RealtimeAgent\Data\AgentSession
    {
        return $this->app->make(AgentSessionManager::class)
            ->make('demo.lesson.teacher')
            ->provider('fake')
            ->instructions('Teach the photosynthesis lesson.')
            ->context(['lesson_id' => 123])
            ->goals([
                Goal::make('topic_1')->label('Photosynthesis')->required()->completionByAgent(true),
                Goal::make('topic_2')->label('Light-dependent phase')->required()->completionByAgent(true),
                Goal::make('topic_3')->label('Calvin cycle')->required()->completionByAgent(true),
            ])
            ->tools([
                RuntimeStateGet::class,
                UpdateGoal::class,
                UpdateWorkingMemory::class,
            ])
            ->surface('lesson.show')
            ->interactionLevel(InteractionLevel::Guide)
            ->startFor((object) ['id' => 1]);
    }
}
