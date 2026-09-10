<?php

declare(strict_types=1);

namespace AgentsFullDuplex\RealtimeAgent\Data;

use JsonSerializable;

final readonly class AgentState implements JsonSerializable
{
    /** @param array<string, mixed> $data */
    private function __construct(private array $data) {}

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self($data);
    }

    public function sessionId(): string
    {
        return (string) $this->data['session']['id'];
    }

    public function revision(): int
    {
        return (int) $this->data['session']['revision'];
    }

    public function status(): string
    {
        return (string) $this->data['session']['status'];
    }

    /** @return list<array<string, mixed>> */
    public function goals(): array
    {
        return $this->data['mission']['goals'] ?? [];
    }

    /** @return array<string, mixed> */
    public function workingMemory(): array
    {
        return $this->data['working_memory'] ?? [];
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return $this->data;
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return $this->data;
    }
}
