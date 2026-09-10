<?php

declare(strict_types=1);

namespace AgentsFullDuplex\RealtimeAgent\Contracts;

use AgentsFullDuplex\RealtimeAgent\Data\AgentSession;
use AgentsFullDuplex\RealtimeAgent\Data\ToolCall;

interface ToolHandlerContract
{
    /** @return array<string, mixed> */
    public function handle(AgentSession $session, ToolCall $call): array;
}
