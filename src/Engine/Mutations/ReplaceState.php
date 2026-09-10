<?php

declare(strict_types=1);

namespace AgentsFullDuplex\RealtimeAgent\Engine\Mutations;

use AgentsFullDuplex\RealtimeAgent\Contracts\StateMutation;
use AgentsFullDuplex\RealtimeAgent\Data\AgentState;

final readonly class ReplaceState implements StateMutation
{
    /** @param callable(AgentState): array<string, mixed> $callback */
    public function __construct(private mixed $callback) {}

    public function apply(AgentState $state): array
    {
        return ($this->callback)($state);
    }
}
