<?php

declare(strict_types=1);

namespace AgentsFullDuplex\RealtimeAgent\State;

use AgentsFullDuplex\RealtimeAgent\Contracts\ConversationStoreContract;
use AgentsFullDuplex\RealtimeAgent\Data\ConversationMessage;
use Illuminate\Support\Str;

final class ArrayConversationStore implements ConversationStoreContract
{
    /** @var array<string, list<ConversationMessage>> */
    private array $messages = [];

    /** @var array<string, array<string, int>> */
    private array $indexes = [];

    /** @param array<string, mixed> $message */
    public function record(string $sessionId, array $message): ConversationMessage
    {
        $records = $this->messages[$sessionId] ?? [];

        $key = hash('sha256', (string) $message['idempotency_key']);
        $index = $this->indexes[$sessionId][$key] ?? null;

        if ($index !== null) {
            $existing = $records[$index];

            return $this->messages[$sessionId][$index] = $this->make(
                id: $existing->id,
                sessionId: $sessionId,
                sequence: $existing->sequence,
                message: $message,
            );
        }

        $created = $this->make(
            id: (string) Str::ulid(),
            sessionId: $sessionId,
            sequence: count($records) + 1,
            message: $message,
        );
        $this->messages[$sessionId][] = $created;
        $this->indexes[$sessionId][$key] = count($records);

        return $created;
    }

    public function all(string $sessionId): array
    {
        return $this->messages[$sessionId] ?? [];
    }

    /** @param array<string, mixed> $message */
    private function make(string $id, string $sessionId, int $sequence, array $message): ConversationMessage
    {
        return new ConversationMessage(
            id: $id,
            sessionId: $sessionId,
            sequence: $sequence,
            provider: (string) $message['provider'],
            providerEventId: isset($message['provider_event_id']) ? (string) $message['provider_event_id'] : null,
            role: (string) $message['role'],
            direction: (string) $message['direction'],
            modality: (string) $message['modality'],
            status: (string) $message['status'],
            content: (string) $message['content'],
            metadata: (array) ($message['metadata'] ?? []),
            occurredAt: (string) $message['occurred_at'],
        );
    }
}
