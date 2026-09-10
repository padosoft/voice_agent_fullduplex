<?php

declare(strict_types=1);

namespace AgentsFullDuplex\RealtimeAgent\State;

use AgentsFullDuplex\RealtimeAgent\Contracts\UsageStoreContract;
use AgentsFullDuplex\RealtimeAgent\Data\UsageRecord;
use Illuminate\Support\Str;

final class ArrayUsageStore implements UsageStoreContract
{
    /** @var array<string, list<UsageRecord>> */
    private array $records = [];

    /** @var array<string, array<string, int>> */
    private array $indexes = [];

    /** @param array<string, mixed> $usage */
    public function record(string $sessionId, array $usage): UsageRecord
    {
        $records = $this->records[$sessionId] ?? [];

        $key = hash('sha256', (string) $usage['idempotency_key']);
        $index = $this->indexes[$sessionId][$key] ?? null;

        if ($index !== null) {
            $existing = $records[$index];

            return $this->records[$sessionId][$index] = $this->make(
                id: $existing->id,
                sessionId: $sessionId,
                sequence: $existing->sequence,
                usage: $usage,
            );
        }

        $created = $this->make(
            id: (string) Str::ulid(),
            sessionId: $sessionId,
            sequence: count($records) + 1,
            usage: $usage,
        );
        $this->records[$sessionId][] = $created;
        $this->indexes[$sessionId][$key] = count($records);

        return $created;
    }

    public function all(string $sessionId): array
    {
        return $this->records[$sessionId] ?? [];
    }

    /** @param array<string, mixed> $usage */
    private function make(string $id, string $sessionId, int $sequence, array $usage): UsageRecord
    {
        return new UsageRecord(
            id: $id,
            sessionId: $sessionId,
            sequence: $sequence,
            provider: (string) $usage['provider'],
            providerEventId: isset($usage['provider_event_id']) ? (string) $usage['provider_event_id'] : null,
            kind: (string) $usage['kind'],
            model: isset($usage['model']) ? (string) $usage['model'] : null,
            units: (array) $usage['units'],
            raw: (array) ($usage['raw'] ?? []),
            pricing: isset($usage['pricing']) ? (array) $usage['pricing'] : null,
            amount: isset($usage['amount']) ? (string) $usage['amount'] : null,
            currency: (string) $usage['currency'],
            status: (string) $usage['status'],
            occurredAt: (string) $usage['occurred_at'],
        );
    }
}
