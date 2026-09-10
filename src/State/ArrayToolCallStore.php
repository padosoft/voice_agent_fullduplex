<?php

declare(strict_types=1);

namespace AgentsFullDuplex\RealtimeAgent\State;

use AgentsFullDuplex\RealtimeAgent\Contracts\ToolCallStoreContract;
use AgentsFullDuplex\RealtimeAgent\Data\ToolCall;
use AgentsFullDuplex\RealtimeAgent\Data\ToolResult;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Support\Str;
use Throwable;

final class ArrayToolCallStore implements ToolCallStoreContract
{
    /** @var array<string, array{id: string, session_id: string, call: ToolCall, status: string, started_at: string, result?: ToolResult, error?: array<string, string>, completed_at?: string}> */
    private array $calls = [];

    public function __construct(private readonly Config $config) {}

    public function allForSession(string $sessionId): array
    {
        return array_values(array_map(
            fn (array $entry): array => [
                'id' => $entry['id'],
                'provider_call_id' => $entry['call']->providerCallId,
                'tool' => $entry['call']->name,
                'arguments' => $this->argumentsForAudit($entry['call']),
                'base_revision' => $entry['call']->baseRevision,
                'state_revision_before' => $entry['call']->baseRevision,
                'state_revision_after' => isset($entry['result']) ? $entry['result']->stateRevision : null,
                'authorization_status' => 'allowed',
                'confirmation_status' => $entry['call']->confirmed ? 'accepted' : 'not_required',
                'status' => $entry['status'],
                'result' => isset($entry['result']) ? $entry['result']->toArray() : null,
                'error' => $entry['error'] ?? null,
                'started_at' => $entry['started_at'],
                'completed_at' => $entry['completed_at'] ?? null,
            ],
            array_filter($this->calls, static fn (array $entry): bool => $entry['session_id'] === $sessionId),
        ));
    }

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

        $this->calls[$key] = [
            'id' => (string) Str::ulid(),
            'session_id' => $sessionId,
            'call' => $call,
            'status' => 'started',
            'started_at' => now()->toISOString(),
        ];

        return true;
    }

    public function succeed(string $sessionId, ToolCall $call, ToolResult $result): void
    {
        $key = $this->key($sessionId, $call->idempotencyKey);
        $this->calls[$key] = [
            ...$this->calls[$key],
            'status' => 'completed',
            'result' => $result,
            'completed_at' => now()->toISOString(),
        ];
    }

    public function fail(string $sessionId, ToolCall $call, Throwable $error): void
    {
        $key = $this->key($sessionId, $call->idempotencyKey);
        $this->calls[$key] = [
            ...$this->calls[$key],
            'status' => 'failed',
            'error' => ['type' => $error::class, 'message' => $error->getMessage()],
            'completed_at' => now()->toISOString(),
        ];
    }

    private function key(string $sessionId, string $idempotencyKey): string
    {
        return hash('sha256', $sessionId.':'.$idempotencyKey);
    }

    /** @return array<string, mixed> */
    private function argumentsForAudit(ToolCall $call): array
    {
        $arguments = $call->arguments;

        if ($this->config->get('realtime-agent.security.redact_tool_arguments', true)) {
            $arguments = array_fill_keys(array_keys($arguments), '[redacted]');
        }

        return $arguments;
    }
}
