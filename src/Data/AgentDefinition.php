<?php

declare(strict_types=1);

namespace AgentsFullDuplex\RealtimeAgent\Data;

use AgentsFullDuplex\RealtimeAgent\Contracts\FinishPolicyContract;
use AgentsFullDuplex\RealtimeAgent\Enums\InteractionLevel;

final readonly class AgentDefinition
{
    /**
     * @param  array<string, mixed>  $context
     * @param  list<GoalDefinition>  $goals
     * @param  list<class-string|ToolDefinition>  $tools
     * @param  class-string<FinishPolicyContract>  $finishPolicy
     * @param  array<string, string>  $stateOwnership
     */
    public function __construct(
        public string $key,
        public string $provider,
        public string $instructions,
        public array $context,
        public array $goals,
        public array $tools,
        public ?string $surface,
        public InteractionLevel $interactionLevel,
        public bool $allowAgentGoals,
        public string $finishPolicy,
        public array $stateOwnership = [],
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'provider' => $this->provider,
            'instructions' => $this->instructions,
            'context' => $this->context,
            'goals' => array_map(static fn (GoalDefinition $goal): array => $goal->toArray(), $this->goals),
            'tools' => array_map(
                static fn (string|ToolDefinition $tool): array => is_string($tool)
                    ? ['class' => $tool]
                    : ['definition' => $tool->toPersistentArray()],
                $this->tools,
            ),
            'surface' => $this->surface,
            'interaction_level' => $this->interactionLevel->value,
            'allow_agent_goals' => $this->allowAgentGoals,
            'finish_policy' => $this->finishPolicy,
            'state_ownership' => $this->stateOwnership,
        ];
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        $tools = array_map(
            static fn (array $tool): string|ToolDefinition => isset($tool['class'])
                ? (string) $tool['class']
                : ToolDefinition::fromArray((array) $tool['definition']),
            (array) ($data['tools'] ?? []),
        );

        return new self(
            key: (string) $data['key'],
            provider: (string) $data['provider'],
            instructions: (string) ($data['instructions'] ?? ''),
            context: (array) ($data['context'] ?? []),
            goals: array_map(
                static fn (array $goal): GoalDefinition => GoalDefinition::fromArray($goal),
                (array) ($data['goals'] ?? []),
            ),
            tools: $tools,
            surface: isset($data['surface']) ? (string) $data['surface'] : null,
            interactionLevel: InteractionLevel::from((string) ($data['interaction_level'] ?? 'observe')),
            allowAgentGoals: (bool) ($data['allow_agent_goals'] ?? false),
            finishPolicy: (string) $data['finish_policy'],
            stateOwnership: (array) ($data['state_ownership'] ?? []),
        );
    }
}
