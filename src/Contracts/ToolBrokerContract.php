<?php

declare(strict_types=1);

namespace AgentsFullDuplex\RealtimeAgent\Contracts;

use AgentsFullDuplex\RealtimeAgent\Data\AgentSession;
use AgentsFullDuplex\RealtimeAgent\Data\ToolCall;
use AgentsFullDuplex\RealtimeAgent\Data\ToolResult;

interface ToolBrokerContract
{
    public function execute(AgentSession $session, ToolCall $call): ToolResult;
}
