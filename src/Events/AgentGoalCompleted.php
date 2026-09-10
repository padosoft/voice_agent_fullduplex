<?php

declare(strict_types=1);

namespace AgentsFullDuplex\RealtimeAgent\Events;

use AgentsFullDuplex\RealtimeAgent\Data\AgentState;

final readonly class AgentGoalCompleted
{
    public function __construct(
        public string $sessionId,
        public string $goalId,
        public ?string $evidence,
        public AgentState $state,
    ) {}
}
