<?php

declare(strict_types=1);

namespace AgentsFullDuplex\RealtimeAgent\State;

use AgentsFullDuplex\RealtimeAgent\Contracts\ToolCallStoreContract;
use AgentsFullDuplex\RealtimeAgent\Data\ToolCall;
use AgentsFullDuplex\RealtimeAgent\Data\ToolResult;
use AgentsFullDuplex\RealtimeAgent\Models\AgentToolCallRecord;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Str;
use Throwable;

final readonly class DatabaseToolCallStore implements ToolCallStoreContract
{
    public function __construct(
        private Config $config,
        private ConnectionInterface $database,
    ) {}

    public function completed(string $sessionId, string $idempotencyKey): ?ToolResult
    {
        $record = AgentToolCallRecord::query()
            ->where('idempotency_key', $this->key($sessionId, $idempotencyKey))
            ->where('status', 'completed')
            ->first();

        if ($record === null || $record->result === null) {
            return null;
        }

        return new ToolResult(
            callId: $record->result['call_id'],
            status: $record->result['status'],
            output: $record->result['output'],
            error: $record->result['error'],
            stateRevision: (int) $record->result['state_revision'],
        );
    }

    public function begin(string $sessionId, ToolCall $call): bool
    {
        try {
            return $this->database->transaction(function () use ($sessionId, $call): bool {
                $key = $this->key($sessionId, $call->idempotencyKey);
                $record = AgentToolCallRecord::query()
                    ->where('idempotency_key', $key)
                    ->lockForUpdate()
                    ->first();

                if ($record !== null && $record->status !== 'failed') {
                    return false;
                }

                $record ??= new AgentToolCallRecord([
                    'id' => (string) Str::ulid(),
                    'idempotency_key' => $key,
                ]);

                $record->forceFill([
                    'session_id' => $sessionId,
                    'provider_call_id' => $call->providerCallId,
                    'tool' => $call->name,
                    'arguments' => $this->argumentsForAudit($call->arguments),
                    'base_revision' => $call->baseRevision,
                    'state_revision_before' => $call->baseRevision,
                    'authorization_status' => 'allowed',
                    'confirmation_status' => $call->confirmed ? 'accepted' : 'not_required',
                    'status' => 'started',
                    'result' => null,
                    'error' => null,
                    'started_at' => now(),
                    'completed_at' => null,
                ])->save();

                return true;
            });
        } catch (UniqueConstraintViolationException) {
            return false;
        }
    }

    public function succeed(string $sessionId, ToolCall $call, ToolResult $result): void
    {
        $record = AgentToolCallRecord::query()
            ->where('idempotency_key', $this->key($sessionId, $call->idempotencyKey))
            ->firstOrFail();

        $record->forceFill([
            'status' => 'completed',
            'result' => $result->toArray(),
            'state_revision_after' => $result->stateRevision,
            'completed_at' => now(),
        ])->save();
    }

    public function fail(string $sessionId, ToolCall $call, Throwable $error): void
    {
        $record = AgentToolCallRecord::query()
            ->where('idempotency_key', $this->key($sessionId, $call->idempotencyKey))
            ->firstOrFail();

        $record->forceFill([
            'status' => 'failed',
            'error' => ['type' => $error::class, 'message' => $error->getMessage()],
            'completed_at' => now(),
        ])->save();
    }

    private function key(string $sessionId, string $idempotencyKey): string
    {
        return hash('sha256', $sessionId.':'.$idempotencyKey);
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    private function argumentsForAudit(array $arguments): array
    {
        if (! $this->config->get('realtime-agent.security.redact_tool_arguments', true)) {
            return $arguments;
        }

        return array_fill_keys(array_keys($arguments), '[redacted]');
    }
}
