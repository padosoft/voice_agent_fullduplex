<?php

declare(strict_types=1);

namespace AgentsFullDuplex\RealtimeAgent\Data;

use JsonSerializable;

final readonly class ClientConnectionDescriptor implements JsonSerializable
{
    /** @param array<string, mixed> $connection */
    public function __construct(
        public string $sessionId,
        public string $provider,
        public array $connection,
        public AgentState $state,
    ) {
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return [
            'session_id' => $this->sessionId,
            'provider' => $this->provider,
            'connection' => $this->connection,
            'state' => $this->state->toArray(),
        ];
    }
}
