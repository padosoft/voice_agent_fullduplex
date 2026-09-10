<?php

declare(strict_types=1);

namespace AgentsFullDuplex\RealtimeAgent\Tools;

use AgentsFullDuplex\RealtimeAgent\Contracts\ToolContract;
use AgentsFullDuplex\RealtimeAgent\Contracts\ToolHandlerContract;
use AgentsFullDuplex\RealtimeAgent\Data\AgentSession;
use AgentsFullDuplex\RealtimeAgent\Data\ToolCall;
use AgentsFullDuplex\RealtimeAgent\Data\ToolDefinition;
use AgentsFullDuplex\RealtimeAgent\Engine\StateEngine;
use AgentsFullDuplex\RealtimeAgent\Enums\ToolTarget;
use AgentsFullDuplex\RealtimeAgent\Tool;

final readonly class UpdateWorkingMemory implements ToolContract, ToolHandlerContract
{
    public function __construct(private StateEngine $states) {}

    public function definition(): ToolDefinition
    {
        return Tool::make('working_memory.update')
            ->description('Update explicit, auditable operational memory for this session.')
            ->input([
                'type' => 'object',
                'properties' => [
                    'summary' => ['type' => 'string'],
                    'current_step' => ['type' => 'string'],
                    'facts_add' => ['type' => 'array'],
                    'decisions_add' => ['type' => 'array'],
                    'completed_steps_add' => ['type' => 'array'],
                    'tool_outputs_add' => ['type' => 'array'],
                    'notes_add' => ['type' => 'array'],
                ],
                'additionalProperties' => false,
            ])
            ->target(ToolTarget::State)
            ->handler(self::class);
    }

    public function handle(AgentSession $session, ToolCall $call): array
    {
        $state = $this->states->updateWorkingMemory($session, $call->baseRevision, $call->arguments);

        return ['working_memory' => $state->workingMemory(), 'revision' => $state->revision()];
    }
}
