<?php

declare(strict_types=1);

namespace AgentsFullDuplex\RealtimeAgent\Contracts;

use AgentsFullDuplex\RealtimeAgent\Data\CanonicalEvent;

interface EventStoreContract
{
    /** @param array<string, mixed> $payload */
    public function append(
        string $sessionId,
        string $type,
        string $source,
        int $stateRevision,
        array $payload = [],
        ?string $providerEventId = null,
    ): CanonicalEvent;

    /** @return list<CanonicalEvent> */
    public function forSession(string $sessionId): array;
}
