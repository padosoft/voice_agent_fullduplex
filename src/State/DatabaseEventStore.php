<?php

declare(strict_types=1);

namespace AgentsFullDuplex\RealtimeAgent\State;

use AgentsFullDuplex\RealtimeAgent\Contracts\EventStoreContract;
use AgentsFullDuplex\RealtimeAgent\Data\CanonicalEvent;
use AgentsFullDuplex\RealtimeAgent\Models\AgentEventRecord;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Str;

final readonly class DatabaseEventStore implements EventStoreContract
{
    public function __construct(private ConnectionInterface $database)
    {
    }

    public function append(
        string $sessionId,
        string $type,
        string $source,
        int $stateRevision,
        array $payload = [],
        ?string $providerEventId = null,
    ): CanonicalEvent {
        return $this->database->transaction(function () use (
            $sessionId,
            $type,
            $source,
            $stateRevision,
            $payload,
            $providerEventId,
        ): CanonicalEvent {
            $last = AgentEventRecord::query()
                ->where('session_id', $sessionId)
                ->lockForUpdate()
                ->max('seq');

            $event = new CanonicalEvent(
                version: 1,
                id: 'evt_'.Str::ulid(),
                sessionId: $sessionId,
                sequence: ((int) $last) + 1,
                type: $type,
                source: $source,
                timestamp: now()->toISOString(),
                stateRevision: $stateRevision,
                payload: $payload,
                providerEventId: $providerEventId,
            );

            AgentEventRecord::query()->create([
                'id' => $event->id,
                'session_id' => $event->sessionId,
                'seq' => $event->sequence,
                'type' => $event->type,
                'source' => $event->source,
                'payload' => $event->payload,
                'state_revision' => $event->stateRevision,
                'provider_event_id' => $event->providerEventId,
                'created_at' => $event->timestamp,
            ]);

            return $event;
        });
    }

    public function forSession(string $sessionId): array
    {
        return AgentEventRecord::query()
            ->where('session_id', $sessionId)
            ->orderBy('seq')
            ->get()
            ->map(static fn (AgentEventRecord $record): CanonicalEvent => new CanonicalEvent(
                version: 1,
                id: $record->id,
                sessionId: $record->session_id,
                sequence: (int) $record->seq,
                type: $record->type,
                source: $record->source,
                timestamp: $record->created_at->toISOString(),
                stateRevision: (int) $record->state_revision,
                payload: $record->payload,
                providerEventId: $record->provider_event_id,
            ))
            ->all();
    }
}
