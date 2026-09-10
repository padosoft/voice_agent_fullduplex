<?php

declare(strict_types=1);

namespace AgentsFullDuplex\RealtimeAgent\Data;

final readonly class ToolCall
{
    /** @param array<string, mixed> $arguments */
    public function __construct(
        public string $id,
        public string $name,
        public array $arguments,
        public int $baseRevision,
        public string $idempotencyKey,
        public ?string $providerCallId = null,
        public bool $confirmed = false,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'arguments' => $this->arguments,
            'base_revision' => $this->baseRevision,
            'idempotency_key' => $this->idempotencyKey,
            'provider_call_id' => $this->providerCallId,
        ];
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data, bool $confirmed = false): self
    {
        return new self(
            id: (string) $data['id'],
            name: (string) $data['name'],
            arguments: (array) ($data['arguments'] ?? []),
            baseRevision: (int) $data['base_revision'],
            idempotencyKey: (string) $data['idempotency_key'],
            providerCallId: isset($data['provider_call_id']) ? (string) $data['provider_call_id'] : null,
            confirmed: $confirmed,
        );
    }
}
