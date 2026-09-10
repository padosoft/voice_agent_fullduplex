<?php

declare(strict_types=1);

namespace AgentsFullDuplex\RealtimeAgent\Contracts;

use AgentsFullDuplex\RealtimeAgent\Data\UsageRecord;

interface UsageStoreContract
{
    /** @param array<string, mixed> $usage */
    public function record(string $sessionId, array $usage): UsageRecord;

    /** @return list<UsageRecord> */
    public function all(string $sessionId): array;
}
