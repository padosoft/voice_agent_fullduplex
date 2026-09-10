<?php

declare(strict_types=1);

namespace AgentsFullDuplex\RealtimeAgent\Providers\ElevenLabs;

final class ElevenLabsToolRegistry
{
    /** @var array<string, string> */
    private array $providerIds = [];

    public function remember(string $schemaHash, string $providerToolId): void
    {
        $this->providerIds[$schemaHash] = $providerToolId;
    }

    public function find(string $schemaHash): ?string
    {
        return $this->providerIds[$schemaHash] ?? null;
    }
}
