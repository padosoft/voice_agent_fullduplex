<?php

declare(strict_types=1);

namespace AgentsFullDuplex\RealtimeAgent\Data;

final readonly class ProviderToolSet
{
    /** @param list<array<string, mixed>> $tools */
    public function __construct(public array $tools) {}
}
