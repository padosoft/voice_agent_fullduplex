<?php

declare(strict_types=1);

namespace AgentsFullDuplex\RealtimeAgent\Events;

use AgentsFullDuplex\RealtimeAgent\Data\ToolCall;
use Throwable;

final readonly class AgentToolFailed
{
    public function __construct(
        public string $sessionId,
        public ToolCall $call,
        public Throwable $error,
    ) {
    }
}
