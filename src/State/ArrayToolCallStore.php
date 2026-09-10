<?php

declare(strict_types=1);

namespace AgentsFullDuplex\RealtimeAgent\State;

use AgentsFullDuplex\RealtimeAgent\Contracts\ToolCallStoreContract;
use AgentsFullDuplex\RealtimeAgent\Data\ToolCall;
use AgentsFullDuplex\RealtimeAgent\Data\ToolResult;
use Throwable;

final class ArrayToolCallStore implements ToolCallStoreContract
{
    /** @var array<string, array{status: string, result?: ToolResult}> */
    private array $calls = [];

    public function completed(string $sessionId, string $idempotencyKey): ?ToolResult
    {
        $entry = $this->calls[$this->key($sessionId, $idempotencyKey)] ?? null;

        return $entry !== null && $entry['status'] === 'completed'
            ? $entry['result']
            : null;
    }

    public function begin(string $sessionId, ToolCall $call): bool
    {
        $key = $this->key($sessionId, $call->idempotencyKey);

        if (isset($this->calls[$key]) && $this->calls[$key]['status'] !== 'failed') {
            return false;
        }

        $this->calls[$key] = ['status' => 'started'];

        return true;
    }

    public function succeed(string $sessionId, ToolCall $call, ToolResult $result): void
    {
        $this->calls[$this->key($sessionId, $call->idempotencyKey)] = [
            'status' => 'completed',
            'result' => $result,
        ];
    }

    public function fail(string $sessionId, ToolCall $call, Throwable $error): void
    {
        $this->calls[$this->key($sessionId, $call->idempotencyKey)] = ['status' => 'failed'];
    }

    private function key(string $sessionId, string $idempotencyKey): string
    {
        return hash('sha256', $sessionId.':'.$idempotencyKey);
    }
}
