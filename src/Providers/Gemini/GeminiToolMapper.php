<?php

declare(strict_types=1);

namespace AgentsFullDuplex\RealtimeAgent\Providers\Gemini;

use AgentsFullDuplex\RealtimeAgent\Data\ToolDefinition;

final class GeminiToolMapper
{
    /** @return array<string, mixed> */
    public function map(ToolDefinition $tool): array
    {
        return [
            'name' => $this->providerName($tool->name()),
            'description' => $tool->descriptionText(),
            'parametersJsonSchema' => $tool->schema(),
            // Serial Laravel execution is intentional: state revisions and
            // confirmations must complete before Gemini can request the next tool.
            'behavior' => 'BLOCKING',
            'canonical_name' => $tool->name(),
        ];
    }

    public function providerName(string $canonicalName): string
    {
        return str_replace('.', '_', $canonicalName);
    }
}
