<?php

declare(strict_types=1);

namespace AgentsFullDuplex\RealtimeAgent\Data;

use JsonSerializable;

final readonly class CanonicalEvent implements JsonSerializable
{
    /** @param array<string, mixed> $payload */
    public function __construct(
        public int $version,
        public string $id,
        public string $sessionId,
        public int $sequence,
        public string $type,
        public string $source,
        public string $timestamp,
        public int $stateRevision,
        public array $payload,
        public ?string $providerEventId = null,
    ) {}

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return [
            'v' => $this->version,
            'id' => $this->id,
            'session_id' => $this->sessionId,
            'seq' => $this->sequence,
            'type' => $this->type,
            'source' => $this->source,
            'timestamp' => $this->timestamp,
            'state_revision' => $this->stateRevision,
            'payload' => $this->payload,
            'provider_event_id' => $this->providerEventId,
        ];
    }
}
