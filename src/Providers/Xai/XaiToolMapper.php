<?php

declare(strict_types=1);

namespace AgentsFullDuplex\RealtimeAgent\Providers\Xai;

use AgentsFullDuplex\RealtimeAgent\Data\ToolDefinition;

final class XaiToolMapper
{
    /** @return array<string, mixed> */
    public function map(ToolDefinition $tool): array
    {
        return [
            'type' => 'function',
            'name' => $this->providerName($tool->name()),
            'description' => $tool->descriptionText(),
            'parameters' => $tool->schema(),
            'canonical_name' => $tool->name(),
        ];
    }

    public function providerName(string $canonicalName): string
    {
        return str_replace('.', '_', $canonicalName);
    }
}
