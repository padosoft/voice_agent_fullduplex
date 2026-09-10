<?php

declare(strict_types=1);

namespace AgentsFullDuplex\RealtimeAgent\Events;

use AgentsFullDuplex\RealtimeAgent\Data\ToolCall;
use AgentsFullDuplex\RealtimeAgent\Data\ToolResult;

final readonly class AgentToolCalled
{
    public function __construct(
        public string $sessionId,
        public ToolCall $call,
        public ToolResult $result,
    ) {
    }
}
