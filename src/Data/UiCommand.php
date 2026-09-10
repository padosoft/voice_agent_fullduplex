<?php

declare(strict_types=1);

namespace AgentsFullDuplex\RealtimeAgent\Data;

use JsonSerializable;

final readonly class UiCommand implements JsonSerializable
{
    /** @param array<string, mixed> $arguments */
    public function __construct(
        public string $id,
        public string $sessionId,
        public string $callId,
        public int $baseRevision,
        public string $surface,
        public string $action,
        public string $target,
        public array $arguments,
        public string $expiresAt,
        public string $nonce,
    ) {
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return [
            'id' => $this->id,
            'session_id' => $this->sessionId,
            'call_id' => $this->callId,
            'base_revision' => $this->baseRevision,
            'surface' => $this->surface,
            'action' => $this->action,
            'target' => $this->target,
            'arguments' => $this->arguments,
            'expires_at' => $this->expiresAt,
            'nonce' => $this->nonce,
        ];
    }
}
