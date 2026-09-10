<?php

declare(strict_types=1);

namespace AgentsFullDuplex\RealtimeAgent\Engine;

use AgentsFullDuplex\RealtimeAgent\Contracts\ToolContract;
use AgentsFullDuplex\RealtimeAgent\Data\AgentDefinition;
use AgentsFullDuplex\RealtimeAgent\Data\ToolDefinition;
use Illuminate\Contracts\Container\Container;

final readonly class ToolRegistry
{
    public function __construct(private Container $container) {}

    /** @return array<string, ToolDefinition> */
    public function forDefinition(AgentDefinition $definition): array
    {
        $resolved = [];

        foreach ($definition->tools as $tool) {
            $toolDefinition = $tool instanceof ToolDefinition
                ? $tool
                : $this->resolveClass($tool);

            $resolved[$toolDefinition->name()] = $toolDefinition;
        }

        return $resolved;
    }

    /** @param class-string $class */
    private function resolveClass(string $class): ToolDefinition
    {
        $tool = $this->container->make($class);

        if (! $tool instanceof ToolContract) {
            throw new \InvalidArgumentException("Tool class {$class} must implement ".ToolContract::class.'.');
        }

        return $tool->definition();
    }
}
