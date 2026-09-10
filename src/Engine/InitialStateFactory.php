<?php

declare(strict_types=1);

namespace AgentsFullDuplex\RealtimeAgent\Engine;

use AgentsFullDuplex\RealtimeAgent\Data\AgentDefinition;
use AgentsFullDuplex\RealtimeAgent\Data\AgentState;

final class InitialStateFactory
{
    public function __construct(private readonly ToolRegistry $tools)
    {
    }

    public function make(string $sessionId, AgentDefinition $definition, string $startedAt): AgentState
    {
        $goals = array_map(
            static function ($goal) use ($startedAt): array {
                $value = $goal->toArray();
                $value['created_at'] = $startedAt;

                return $value;
            },
            $definition->goals,
        );

        return AgentState::fromArray([
            'schema' => 'realtime-agent-state@1',
            'session' => [
                'id' => $sessionId,
                'agent' => $definition->key,
                'provider' => $definition->provider,
                'status' => 'active',
                'revision' => 1,
                'started_at' => $startedAt,
            ],
            'environment' => [
                'route' => null,
                'ui' => [
                    'surface' => $definition->surface,
                    'revision' => 0,
                    'title' => null,
                    'components' => (object) [],
                ],
            ],
            'mission' => [
                'objective' => $definition->instructions,
                'goals' => $goals,
            ],
            'working_memory' => [
                'summary' => '',
                'facts' => [],
                'decisions' => [],
                'current_step' => '',
                'completed_steps' => [],
                'tool_outputs' => [],
                'notes' => [],
            ],
            'capabilities' => [
                'interaction_level' => $definition->interactionLevel->value,
                'tools' => array_keys($this->tools->forDefinition($definition)),
                'allow_agent_goals' => $definition->allowAgentGoals,
            ],
            'pending' => [
                'actions' => [],
                'confirmations' => [],
            ],
            'application' => $definition->context,
        ]);
    }
}
