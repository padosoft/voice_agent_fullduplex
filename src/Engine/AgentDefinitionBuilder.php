<?php

declare(strict_types=1);

namespace AgentsFullDuplex\RealtimeAgent\Engine;

use AgentsFullDuplex\RealtimeAgent\Contracts\FinishPolicyContract;
use AgentsFullDuplex\RealtimeAgent\Data\AgentDefinition;
use AgentsFullDuplex\RealtimeAgent\Data\GoalDefinition;
use AgentsFullDuplex\RealtimeAgent\Data\StartedAgentSession;
use AgentsFullDuplex\RealtimeAgent\Data\ToolDefinition;
use AgentsFullDuplex\RealtimeAgent\Enums\InteractionLevel;
use AgentsFullDuplex\RealtimeAgent\Policies\AllRequiredGoalsCompleted;

final class AgentDefinitionBuilder
{
    private string $provider;

    private string $instructions = '';

    /** @var array<string, mixed> */
    private array $context = [];

    /** @var list<GoalDefinition> */
    private array $goals = [];

    /** @var list<class-string|ToolDefinition> */
    private array $tools = [];

    private ?string $surface = null;

    private InteractionLevel $interactionLevel = InteractionLevel::Observe;

    private bool $allowAgentGoals = false;

    /** @var array<string, string> */
    private array $stateOwnership = [];

    /** @var class-string<FinishPolicyContract> */
    private string $finishPolicy = AllRequiredGoalsCompleted::class;

    public function __construct(
        private readonly AgentSessionManager $manager,
        private readonly string $key,
        string $defaultProvider,
    ) {
        $this->provider = $defaultProvider;
    }

    public function provider(string $provider): self
    {
        $this->provider = $provider;

        return $this;
    }

    public function instructions(string $instructions): self
    {
        $this->instructions = $instructions;

        return $this;
    }

    /** @param array<string, mixed> $context */
    public function context(array $context): self
    {
        $this->context = $context;

        return $this;
    }

    /** @param iterable<GoalDefinition> $goals */
    public function goals(iterable $goals): self
    {
        $this->goals = [...$goals];

        return $this;
    }

    /** @param iterable<class-string|ToolDefinition> $tools */
    public function tools(iterable $tools): self
    {
        $this->tools = [...$tools];

        return $this;
    }

    public function surface(string $surface): self
    {
        $this->surface = $surface;

        return $this;
    }

    public function interactionLevel(InteractionLevel $level): self
    {
        $this->interactionLevel = $level;

        return $this;
    }

    public function allowAgentGoals(bool $allowed = true): self
    {
        $this->allowAgentGoals = $allowed;

        return $this;
    }

    /** @param array<string, string> $ownership */
    public function stateOwnership(array $ownership): self
    {
        $this->stateOwnership = $ownership;

        return $this;
    }

    /** @param class-string<FinishPolicyContract> $policy */
    public function finishWhen(string $policy): self
    {
        $this->finishPolicy = $policy;

        return $this;
    }

    public function definition(): AgentDefinition
    {
        return new AgentDefinition(
            key: $this->key,
            provider: $this->provider,
            instructions: $this->instructions,
            context: $this->context,
            goals: $this->goals,
            tools: $this->tools,
            surface: $this->surface,
            interactionLevel: $this->interactionLevel,
            allowAgentGoals: $this->allowAgentGoals,
            finishPolicy: $this->finishPolicy,
            stateOwnership: $this->stateOwnership,
        );
    }

    public function startFor(mixed $owner): StartedAgentSession
    {
        return $this->manager->start($this->definition(), $owner);
    }
}
