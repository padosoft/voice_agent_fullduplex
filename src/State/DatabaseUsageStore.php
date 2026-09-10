<?php

declare(strict_types=1);

namespace AgentsFullDuplex\RealtimeAgent\State;

use AgentsFullDuplex\RealtimeAgent\Contracts\UsageStoreContract;
use AgentsFullDuplex\RealtimeAgent\Data\UsageRecord;
use AgentsFullDuplex\RealtimeAgent\Models\AgentSessionRecord;
use AgentsFullDuplex\RealtimeAgent\Models\AgentUsageRecord;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Str;

final readonly class DatabaseUsageStore implements UsageStoreContract
{
    public function __construct(private ConnectionInterface $database) {}

    /** @param array<string, mixed> $usage */
    public function record(string $sessionId, array $usage): UsageRecord
    {
        return $this->database->transaction(function () use ($sessionId, $usage): UsageRecord {
            /** @var AgentSessionRecord $session */
            $session = AgentSessionRecord::query()->lockForUpdate()->findOrFail($sessionId);
            $key = hash('sha256', (string) $usage['idempotency_key']);
            $record = AgentUsageRecord::query()
                ->where('session_id', $sessionId)
                ->where('idempotency_key', $key)
                ->lockForUpdate()
                ->first();

            if ($record === null) {
                $session->usage_sequence = (int) $session->usage_sequence + 1;
                $session->save();
                $record = new AgentUsageRecord([
                    'id' => (string) Str::ulid(),
                    'session_id' => $sessionId,
                    'seq' => $session->usage_sequence,
                    'idempotency_key' => $key,
                ]);
            }

            $record->forceFill([
                'provider' => $usage['provider'],
                'provider_event_id' => $usage['provider_event_id'] ?? null,
                'kind' => $usage['kind'],
                'model' => $usage['model'] ?? null,
                'units' => $usage['units'],
                'raw' => $usage['raw'] ?? [],
                'pricing' => $usage['pricing'] ?? null,
                'amount' => $usage['amount'] ?? null,
                'currency' => $usage['currency'],
                'status' => $usage['status'],
                'occurred_at' => $usage['occurred_at'],
            ])->save();

            return $this->hydrate($record);
        });
    }

    public function all(string $sessionId): array
    {
        return AgentUsageRecord::query()
            ->where('session_id', $sessionId)
            ->orderBy('seq')
            ->get()
            ->map(fn (AgentUsageRecord $record): UsageRecord => $this->hydrate($record))
            ->all();
    }

    private function hydrate(AgentUsageRecord $record): UsageRecord
    {
        return new UsageRecord(
            id: (string) $record->getKey(),
            sessionId: (string) $record->session_id,
            sequence: (int) $record->seq,
            provider: (string) $record->provider,
            providerEventId: $record->provider_event_id !== null ? (string) $record->provider_event_id : null,
            kind: (string) $record->kind,
            model: $record->model !== null ? (string) $record->model : null,
            units: (array) $record->units,
            raw: (array) $record->raw,
            pricing: is_array($record->pricing) ? $record->pricing : null,
            amount: $record->amount !== null ? (string) $record->amount : null,
            currency: (string) $record->currency,
            status: (string) $record->status,
            occurredAt: $record->occurred_at->toISOString(),
        );
    }
}
