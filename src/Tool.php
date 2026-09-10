<?php

declare(strict_types=1);

namespace AgentsFullDuplex\RealtimeAgent;

use AgentsFullDuplex\RealtimeAgent\Data\ToolDefinition;

final class Tool
{
    public static function make(string $name): ToolDefinition
    {
        return ToolDefinition::make($name);
    }
}
