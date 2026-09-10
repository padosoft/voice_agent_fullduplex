<?php

declare(strict_types=1);

namespace AgentsFullDuplex\RealtimeAgent\Tests\Feature;

use AgentsFullDuplex\RealtimeAgent\Data\ToolCall;
use AgentsFullDuplex\RealtimeAgent\Engine\AgentSessionManager;
use AgentsFullDuplex\RealtimeAgent\Engine\StateEngine;
use AgentsFullDuplex\RealtimeAgent\Enums\InteractionLevel;
use AgentsFullDuplex\RealtimeAgent\Exceptions\ToolCallRejected;
use AgentsFullDuplex\RealtimeAgent\Tests\TestCase;
use AgentsFullDuplex\RealtimeAgent\Tools\ExecuteUiAction;

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
        self::assertSame('lesson.topic_1', $result->output['command']['target']);
        self::assertSame(3, $session->state()->revision());
        self::assertCount(1, $session->state()->toArray()['pending']['actions']);
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
}
