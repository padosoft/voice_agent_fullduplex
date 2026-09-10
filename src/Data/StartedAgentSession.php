<?php

declare(strict_types=1);

namespace AgentsFullDuplex\RealtimeAgent\Data;

/**
 * The application-facing result of startFor(). It retains the executable
 * session API while also exposing the initial state and client descriptor.
 */
final class StartedAgentSession extends AgentSession
{
    public function initialState(): AgentState
    {
        return $this->connection()->state;
    }
}
