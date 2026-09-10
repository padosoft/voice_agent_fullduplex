<?php

declare(strict_types=1);

namespace AgentsFullDuplex\RealtimeAgent\Tests\Feature;

use AgentsFullDuplex\RealtimeAgent\Data\ToolCall;
use AgentsFullDuplex\RealtimeAgent\Engine\AgentSessionManager;
use AgentsFullDuplex\RealtimeAgent\Goal;
use AgentsFullDuplex\RealtimeAgent\Tests\TestCase;
use AgentsFullDuplex\RealtimeAgent\Tools\UpdateGoal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

final class DatabasePersistenceTest extends TestCase
{
    use RefreshDatabase;

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('realtime-agent.state.driver', 'database');
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);
    }

    public function test_database_driver_persists_state_events_and_idempotent_tool_results(): void
    {
        $session = $this->app->make(AgentSessionManager::class)
            ->make('database.lesson')
            ->provider('fake')
            ->goals([
                Goal::make('topic_1')->required()->completionByAgent(true),
            ])
            ->tools([UpdateGoal::class])
            ->startFor(null);

        $call = new ToolCall(
            id: 'call_1',
            name: 'goal.update',
            arguments: [
                'goal_id' => 'topic_1',
                'status' => 'completed',
                'evidence' => 'Database-backed evidence.',
            ],
            baseRevision: 1,
            idempotencyKey: 'persistent-key',
        );

        $first = $session->execute($call);
        $second = $session->execute($call);

        self::assertEquals($first, $second);
        self::assertSame(2, $session->state()->revision());
        self::assertSame('completed', $session->state()->status());
        self::assertSame(1, DB::table('realtime_agent_sessions')->count());
        self::assertSame(1, DB::table('realtime_agent_tool_calls')->count());
        self::assertGreaterThanOrEqual(4, DB::table('realtime_agent_events')->count());
        self::assertSame(
            '[redacted]',
            json_decode(DB::table('realtime_agent_tool_calls')->value('arguments'), true)['evidence'],
        );
    }
}
