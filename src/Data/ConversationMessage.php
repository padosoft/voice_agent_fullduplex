<?php

declare(strict_types=1);

namespace AgentsFullDuplex\RealtimeAgent\Data;

use JsonSerializable;

final readonly class ConversationMessage implements JsonSerializable
{
    /** @param array<string, mixed> $metadata */
    public function __construct(
        public string $id,
        public string $sessionId,
        public int $sequence,
        public string $provider,
        public ?string $providerEventId,
        public string $role,
        public string $direction,
        public string $modality,
        public string $status,
        public string $content,
        public array $metadata,
        public string $occurredAt,
    ) {}

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return [
            'id' => $this->id,
            'session_id' => $this->sessionId,
            'seq' => $this->sequence,
            'provider' => $this->provider,
            'provider_event_id' => $this->providerEventId,
            'role' => $this->role,
            'direction' => $this->direction,
            'modality' => $this->modality,
            'status' => $this->status,
            'content' => $this->content,
            'metadata' => $this->metadata,
            'occurred_at' => $this->occurredAt,
        ];
    }
}
