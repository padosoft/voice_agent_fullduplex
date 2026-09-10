<?php

declare(strict_types=1);

namespace AgentsFullDuplex\RealtimeAgent\Tools;

use AgentsFullDuplex\RealtimeAgent\Contracts\ToolContract;
use AgentsFullDuplex\RealtimeAgent\Contracts\ToolHandlerContract;
use AgentsFullDuplex\RealtimeAgent\Data\AgentSession;
use AgentsFullDuplex\RealtimeAgent\Data\ToolCall;
use AgentsFullDuplex\RealtimeAgent\Data\ToolDefinition;
use AgentsFullDuplex\RealtimeAgent\Enums\ToolTarget;
use AgentsFullDuplex\RealtimeAgent\Tool;

final class RuntimeStateGet implements ToolContract, ToolHandlerContract
{
    public function definition(): ToolDefinition
    {
        return Tool::make('runtime.state.get')
            ->description('Read the latest canonical state for this session.')
            ->input(['type' => 'object', 'properties' => [], 'additionalProperties' => false])
            ->target(ToolTarget::State)
            ->handler(self::class);
    }

    public function handle(AgentSession $session, ToolCall $call): array
    {
        return ['state' => $session->state()->toArray()];
    }
}
