<?php

declare(strict_types=1);

namespace AgentsFullDuplex\RealtimeAgent\Engine;

use AgentsFullDuplex\RealtimeAgent\Exceptions\ToolCallRejected;

final class JsonSchemaValidator
{
    /**
     * A deliberately small JSON Schema subset for tool arguments. Applications
     * can replace the broker when they need a complete Draft implementation.
     *
     * @param array<string, mixed> $schema
     * @param array<string, mixed> $arguments
     */
    public function validate(array $schema, array $arguments): void
    {
        $required = $schema['required'] ?? [];

        foreach ($required as $name) {
            if (! array_key_exists($name, $arguments)) {
                throw new ToolCallRejected("Missing required argument: {$name}.");
            }
        }

        if (($schema['additionalProperties'] ?? true) === false) {
            $unknown = array_diff(array_keys($arguments), array_keys($schema['properties'] ?? []));

            if ($unknown !== []) {
                throw new ToolCallRejected('Unknown argument: '.reset($unknown).'.');
            }
        }

        foreach ($schema['properties'] ?? [] as $name => $property) {
            if (! array_key_exists($name, $arguments)) {
                continue;
            }

            $value = $arguments[$name];
            $type = $property['type'] ?? null;
            $valid = match ($type) {
                'string' => is_string($value),
                'integer' => is_int($value),
                'number' => is_int($value) || is_float($value),
                'boolean' => is_bool($value),
                'array' => is_array($value) && array_is_list($value),
                'object' => is_array($value),
                null => true,
                default => false,
            };

            if (! $valid) {
                throw new ToolCallRejected("Argument {$name} must be of type {$type}.");
            }

            if (isset($property['enum']) && ! in_array($value, $property['enum'], true)) {
                throw new ToolCallRejected("Argument {$name} has an unsupported value.");
            }
        }
    }
}
