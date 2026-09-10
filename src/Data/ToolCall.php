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
    ) {
    }
}
