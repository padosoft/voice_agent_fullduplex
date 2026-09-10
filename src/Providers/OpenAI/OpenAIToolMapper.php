<?php

declare(strict_types=1);

namespace AgentsFullDuplex\RealtimeAgent\Providers\OpenAI;

use AgentsFullDuplex\RealtimeAgent\Data\ToolDefinition;

final class OpenAIToolMapper
{
    /** @return array<string, mixed> */
    public function map(ToolDefinition $tool): array
    {
        return [
            'type' => 'function',
            'name' => $tool->name(),
            'description' => $tool->toArray()['description'],
            'parameters' => $tool->schema(),
        ];
    }
}
