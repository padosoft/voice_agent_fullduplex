<?php

declare(strict_types=1);

namespace AgentsFullDuplex\RealtimeAgent\Contracts;

use AgentsFullDuplex\RealtimeAgent\Data\AgentDefinition;
use AgentsFullDuplex\RealtimeAgent\Data\AgentState;

interface StateStoreContract
{
    public function create(AgentState $state, mixed $owner, AgentDefinition $definition): AgentState;

    public function get(string $sessionId): AgentState;

    public function definition(string $sessionId): AgentDefinition;

    public function owner(string $sessionId): mixed;

    public function mutate(
        string $sessionId,
        int $baseRevision,
        StateMutation $mutation,
    ): AgentState;
}
