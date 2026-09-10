<?php

declare(strict_types=1);

namespace AgentsFullDuplex\RealtimeAgent\Providers\ElevenLabs;

use AgentsFullDuplex\RealtimeAgent\Data\ToolDefinition;

final class ElevenLabsToolMapper
{
    public function schemaHash(ToolDefinition $tool): string
    {
        return hash('sha256', json_encode($tool->toArray(), JSON_THROW_ON_ERROR));
    }
}
