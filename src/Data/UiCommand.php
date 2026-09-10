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
        public ?string $token = null,
    ) {}

    public function withToken(string $token): self
    {
        return new self(
            id: $this->id,
            sessionId: $this->sessionId,
            callId: $this->callId,
            baseRevision: $this->baseRevision,
            surface: $this->surface,
            action: $this->action,
            target: $this->target,
            arguments: $this->arguments,
            expiresAt: $this->expiresAt,
            nonce: $this->nonce,
            token: $token,
        );
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            id: (string) $data['id'],
            sessionId: (string) $data['session_id'],
            callId: (string) $data['call_id'],
            baseRevision: (int) $data['base_revision'],
            surface: (string) $data['surface'],
            action: (string) $data['action'],
            target: (string) $data['target'],
            arguments: (array) ($data['arguments'] ?? []),
            expiresAt: (string) $data['expires_at'],
            nonce: (string) $data['nonce'],
            token: isset($data['token']) ? (string) $data['token'] : null,
        );
    }

    /** @return array<string, mixed> */
    public function unsignedPayload(): array
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

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return [...$this->unsignedPayload(), 'token' => $this->token];
    }
}
