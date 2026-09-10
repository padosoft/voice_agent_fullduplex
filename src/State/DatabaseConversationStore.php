<?php

declare(strict_types=1);

namespace AgentsFullDuplex\RealtimeAgent\State;

use AgentsFullDuplex\RealtimeAgent\Contracts\ConversationStoreContract;
use AgentsFullDuplex\RealtimeAgent\Data\ConversationMessage;
use AgentsFullDuplex\RealtimeAgent\Models\AgentMessageRecord;
use AgentsFullDuplex\RealtimeAgent\Models\AgentSessionRecord;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Str;

final readonly class DatabaseConversationStore implements ConversationStoreContract
{
    public function __construct(private ConnectionInterface $database) {}

    /** @param array<string, mixed> $message */
    public function record(string $sessionId, array $message): ConversationMessage
    {
        return $this->database->transaction(function () use ($sessionId, $message): ConversationMessage {
            /** @var AgentSessionRecord $session */
            $session = AgentSessionRecord::query()->lockForUpdate()->findOrFail($sessionId);
            $key = hash('sha256', (string) $message['idempotency_key']);
            $record = AgentMessageRecord::query()
                ->where('session_id', $sessionId)
                ->where('idempotency_key', $key)
                ->lockForUpdate()
                ->first();

            if ($record === null) {
                $session->message_sequence = (int) $session->message_sequence + 1;
                $session->save();
                $record = new AgentMessageRecord([
                    'id' => (string) Str::ulid(),
                    'session_id' => $sessionId,
                    'seq' => $session->message_sequence,
                    'idempotency_key' => $key,
                ]);
            }

            $record->forceFill([
                'provider' => $message['provider'],
                'provider_event_id' => $message['provider_event_id'] ?? null,
                'role' => $message['role'],
                'direction' => $message['direction'],
                'modality' => $message['modality'],
                'status' => $message['status'],
                'content' => $message['content'],
                'metadata' => $message['metadata'] ?? [],
                'occurred_at' => $message['occurred_at'],
            ])->save();

            return $this->hydrate($record);
        });
    }

    public function all(string $sessionId): array
    {
        return AgentMessageRecord::query()
            ->where('session_id', $sessionId)
            ->orderBy('seq')
            ->get()
            ->map(fn (AgentMessageRecord $record): ConversationMessage => $this->hydrate($record))
            ->all();
    }

    private function hydrate(AgentMessageRecord $record): ConversationMessage
    {
        return new ConversationMessage(
            id: (string) $record->getKey(),
            sessionId: (string) $record->session_id,
            sequence: (int) $record->seq,
            provider: (string) $record->provider,
            providerEventId: $record->provider_event_id !== null ? (string) $record->provider_event_id : null,
            role: (string) $record->role,
            direction: (string) $record->direction,
            modality: (string) $record->modality,
            status: (string) $record->status,
            content: (string) $record->content,
            metadata: (array) $record->metadata,
            occurredAt: $record->occurred_at->toISOString(),
        );
    }
}
