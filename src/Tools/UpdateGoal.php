<?php

declare(strict_types=1);

namespace AgentsFullDuplex\RealtimeAgent\Tools;

use AgentsFullDuplex\RealtimeAgent\Contracts\ToolContract;
use AgentsFullDuplex\RealtimeAgent\Contracts\ToolHandlerContract;
use AgentsFullDuplex\RealtimeAgent\Data\AgentSession;
use AgentsFullDuplex\RealtimeAgent\Data\ToolCall;
use AgentsFullDuplex\RealtimeAgent\Data\ToolDefinition;
use AgentsFullDuplex\RealtimeAgent\Engine\GoalEngine;
use AgentsFullDuplex\RealtimeAgent\Enums\GoalStatus;
use AgentsFullDuplex\RealtimeAgent\Enums\ToolTarget;
use AgentsFullDuplex\RealtimeAgent\Tool;

final readonly class UpdateGoal implements ToolContract, ToolHandlerContract
{
    public function __construct(private GoalEngine $goals)
    {
    }

    public function definition(): ToolDefinition
    {
        return Tool::make('goal.update')
            ->description('Change a declared goal through the authoritative goal engine.')
            ->input([
                'type' => 'object',
                'properties' => [
                    'goal_id' => ['type' => 'string'],
                    'status' => [
                        'type' => 'string',
                        'enum' => array_column(GoalStatus::cases(), 'value'),
                    ],
                    'evidence' => ['type' => 'string'],
                ],
                'required' => ['goal_id', 'status'],
                'additionalProperties' => false,
            ])
            ->target(ToolTarget::Goal)
            ->handler(self::class);
    }

    public function handle(AgentSession $session, ToolCall $call): array
    {
        $state = $this->goals->update(
            $session,
            $call->baseRevision,
            $call->arguments['goal_id'],
            GoalStatus::from($call->arguments['status']),
            $call->arguments['evidence'] ?? null,
        );

        return [
            'goals' => $state->goals(),
            'session_status' => $state->status(),
            'revision' => $state->revision(),
        ];
    }
}
