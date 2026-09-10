<?php

declare(strict_types=1);

namespace AgentsFullDuplex\RealtimeAgent\State;

use AgentsFullDuplex\RealtimeAgent\Contracts\EventStoreContract;
use AgentsFullDuplex\RealtimeAgent\Data\CanonicalEvent;
use Illuminate\Support\Str;

final class ArrayEventStore implements EventStoreContract
{
    /** @var array<string, list<CanonicalEvent>> */
    private array $events = [];

    public function append(
        string $sessionId,
        string $type,
        string $source,
        int $stateRevision,
        array $payload = [],
        ?string $providerEventId = null,
    ): CanonicalEvent {
        $sequence = count($this->events[$sessionId] ?? []) + 1;
        $event = new CanonicalEvent(
            version: 1,
            id: 'evt_'.Str::ulid(),
            sessionId: $sessionId,
            sequence: $sequence,
            type: $type,
            source: $source,
            timestamp: now()->toISOString(),
            stateRevision: $stateRevision,
            payload: $payload,
            providerEventId: $providerEventId,
        );

        $this->events[$sessionId][] = $event;

        return $event;
    }

    public function forSession(string $sessionId): array
    {
        return $this->events[$sessionId] ?? [];
    }
}
