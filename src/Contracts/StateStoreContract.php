<?php

declare(strict_types=1);

namespace AgentsFullDuplex\RealtimeAgent\Contracts;

use AgentsFullDuplex\RealtimeAgent\Data\AgentState;

interface StateStoreContract
{
    public function create(AgentState $state, mixed $owner = null): AgentState;

    public function get(string $sessionId): AgentState;

    public function mutate(
        string $sessionId,
        int $baseRevision,
        StateMutation $mutation,
    ): AgentState;
}
