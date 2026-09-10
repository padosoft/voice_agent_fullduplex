<?php

declare(strict_types=1);

namespace AgentsFullDuplex\RealtimeAgent\Contracts;

use AgentsFullDuplex\RealtimeAgent\Data\AgentState;

interface StateMutation
{
    /**
     * Return the complete next state body. The store owns the revision increment.
     *
     * @return array<string, mixed>
     */
    public function apply(AgentState $state): array;
}
