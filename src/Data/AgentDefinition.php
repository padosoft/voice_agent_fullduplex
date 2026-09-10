<?php

declare(strict_types=1);

namespace AgentsFullDuplex\RealtimeAgent\Data;

use AgentsFullDuplex\RealtimeAgent\Contracts\FinishPolicyContract;
use AgentsFullDuplex\RealtimeAgent\Enums\InteractionLevel;

final readonly class AgentDefinition
{
    /**
     * @param array<string, mixed> $context
     * @param list<GoalDefinition> $goals
     * @param list<class-string|ToolDefinition> $tools
     * @param class-string<FinishPolicyContract> $finishPolicy
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
    ) {
    }
}
