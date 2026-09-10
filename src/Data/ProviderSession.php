<?php

declare(strict_types=1);

namespace AgentsFullDuplex\RealtimeAgent\Data;

final readonly class ProviderSession
{
    /** @param array<string, mixed> $metadata */
    public function __construct(
        public ?string $providerSessionId,
        public array $metadata = [],
    ) {}
}
