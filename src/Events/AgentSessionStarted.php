<?php

declare(strict_types=1);

namespace AgentsFullDuplex\RealtimeAgent\Events;

use AgentsFullDuplex\RealtimeAgent\Data\AgentSession;

final readonly class AgentSessionStarted
{
    public function __construct(public AgentSession $session)
    {
    }
}
