<?php

declare(strict_types=1);

namespace AgentsFullDuplex\RealtimeAgent\Events;

use AgentsFullDuplex\RealtimeAgent\Data\AgentState;

final readonly class AgentStateChanged
{
    public function __construct(
        public string $sessionId,
        public AgentState $state,
        public string $path,
    ) {}
}
