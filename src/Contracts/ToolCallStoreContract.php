<?php

declare(strict_types=1);

namespace AgentsFullDuplex\RealtimeAgent\Contracts;

use AgentsFullDuplex\RealtimeAgent\Data\ToolCall;
use AgentsFullDuplex\RealtimeAgent\Data\ToolResult;
use Throwable;

interface ToolCallStoreContract
{
    public function completed(string $sessionId, string $idempotencyKey): ?ToolResult;

    /** Return false when another execution already owns this idempotency key. */
    public function begin(string $sessionId, ToolCall $call): bool;

    public function succeed(string $sessionId, ToolCall $call, ToolResult $result): void;

    public function fail(string $sessionId, ToolCall $call, Throwable $error): void;
}
