<?php

declare(strict_types=1);

namespace AgentsFullDuplex\RealtimeAgent\Tests\Feature;

use AgentsFullDuplex\RealtimeAgent\Data\ToolCall;
use AgentsFullDuplex\RealtimeAgent\Data\UiCommand;
use AgentsFullDuplex\RealtimeAgent\Engine\AgentSessionManager;
use AgentsFullDuplex\RealtimeAgent\Engine\StateEngine;
use AgentsFullDuplex\RealtimeAgent\Engine\UiCommandSigner;
use AgentsFullDuplex\RealtimeAgent\Enums\InteractionLevel;
use AgentsFullDuplex\RealtimeAgent\Exceptions\ExpiredUiCommand;
use AgentsFullDuplex\RealtimeAgent\Exceptions\ToolCallRejected;
use AgentsFullDuplex\RealtimeAgent\Tests\TestCase;
use AgentsFullDuplex\RealtimeAgent\Tools\ExecuteUiAction;
use Illuminate\Support\Carbon;

final class UiActionTest extends TestCase
{
    public function test_only_exposed_semantic_ui_actions_become_commands(): void
    {
        $session = $this->app->make(AgentSessionManager::class)
            ->make('demo.ui.guide')
            ->provider('fake')
            ->tools([ExecuteUiAction::class])
            ->surface('lesson.show')
            ->interactionLevel(InteractionLevel::Guide)
            ->startFor((object) ['id' => 1]);

        $this->app->make(StateEngine::class)->updateSurface($session, 1, [
            'surface' => 'lesson.show',
            'title' => 'Lesson',
            'components' => [
                'lesson.topic_1' => [
                    'type' => 'goal',
                    'label' => 'Topic 1',
                    'state' => ['completed' => false],
                    'actions' => ['highlight'],
                ],
            ],
        ]);

        $result = $session->execute(new ToolCall(
            id: 'ui_1',
            name: 'ui.action',
            arguments: [
                'surface' => 'lesson.show',
                'action' => 'highlight',
                'target' => 'lesson.topic_1',
            ],
            baseRevision: 2,
            idempotencyKey: 'ui-key',
        ));

        self::assertSame('cmd_', substr($result->output['command']['id'], 0, 4));
        self::assertSame('awaiting_client', $result->status);
        self::assertSame('lesson.topic_1', $result->output['command']['target']);
        self::assertSame(3, $session->state()->revision());
        self::assertCount(1, $session->state()->toArray()['pending']['actions']);
        self::assertSame(64, strlen($result->output['command']['token']));

        $completed = $this->app->make(StateEngine::class)->completeUiCommand(
            session: $session,
            baseRevision: 3,
            commandId: $result->output['command']['id'],
            token: $result->output['command']['token'],
            result: ['status' => 'completed'],
            surfaceSnapshot: [
                'surface' => 'lesson.show',
                'title' => 'Lesson',
                'components' => [],
            ],
        );

        self::assertSame(4, $completed->revision());
        self::assertSame([], $completed->toArray()['pending']['actions']);
    }

    public function test_raw_selectors_are_not_valid_targets(): void
    {
        $session = $this->app->make(AgentSessionManager::class)
            ->make('demo.ui.guide')
            ->provider('fake')
            ->tools([ExecuteUiAction::class])
            ->surface('lesson.show')
            ->interactionLevel(InteractionLevel::Guide)
            ->startFor(null);

        $this->expectException(ToolCallRejected::class);
        $session->execute(new ToolCall(
            id: 'ui_bad',
            name: 'ui.action',
            arguments: [
                'surface' => 'lesson.show',
                'action' => 'click',
                'target' => '#delete-everything',
            ],
            baseRevision: 1,
            idempotencyKey: 'ui-bad-key',
        ));
    }

    public function test_expired_ui_commands_are_rejected(): void
    {
        Carbon::setTestNow('2026-09-10T12:00:00Z');
        $session = $this->app->make(AgentSessionManager::class)
            ->make('demo.ui.expiry')
            ->startFor(null);
        $unsigned = new UiCommand(
            id: 'cmd_expired',
            sessionId: $session->id,
            callId: 'call_expired',
            baseRevision: 1,
            surface: 'lesson.show',
            action: 'highlight',
            target: 'lesson.topic_1',
            arguments: [],
            expiresAt: now()->addSeconds(15)->toISOString(),
            nonce: 'expiry-nonce',
        );
        $signer = $this->app->make(UiCommandSigner::class);
        $command = $unsigned->withToken($signer->sign($unsigned));
        $states = $this->app->make(StateEngine::class);
        $states->queueUiCommand($session, 1, $command);
        Carbon::setTestNow('2026-09-10T12:01:00Z');

        $this->expectException(ExpiredUiCommand::class);

        try {
            $states->completeUiCommand(
                session: $session,
                baseRevision: 2,
                commandId: $command->id,
                token: (string) $command->token,
                result: ['status' => 'completed'],
            );
        } finally {
            Carbon::setTestNow();
        }
    }
}
