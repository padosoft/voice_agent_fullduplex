<?php

declare(strict_types=1);

namespace AgentsFullDuplex\RealtimeAgent\Data;

use AgentsFullDuplex\RealtimeAgent\Confirmation;
use AgentsFullDuplex\RealtimeAgent\Enums\ToolTarget;
use JsonSerializable;

final class ToolDefinition implements JsonSerializable
{
    private string $description = '';

    /** @var array<string, mixed> */
    private array $input = ['type' => 'object', 'properties' => []];

    private ToolTarget $target = ToolTarget::Server;

    /** @var class-string|callable|null */
    private mixed $handler = null;

    /** @var class-string|callable|null */
    private mixed $authorizer = null;

    private string $confirmation = 'never';

    private function __construct(private readonly string $name) {}

    public static function make(string $name): self
    {
        return new self($name);
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        $tool = new self((string) $data['name']);
        $tool->description = (string) ($data['description'] ?? '');
        $tool->input = (array) ($data['input_schema'] ?? ['type' => 'object', 'properties' => []]);
        $tool->target = ToolTarget::from((string) ($data['target'] ?? ToolTarget::Server->value));
        $tool->handler = $data['handler'] ?? null;
        $tool->authorizer = $data['authorizer'] ?? null;
        $tool->confirmation = (string) ($data['confirmation'] ?? 'never');

        return $tool;
    }

    public function name(): string
    {
        return $this->name;
    }

    public function description(string $description): self
    {
        $this->description = $description;

        return $this;
    }

    /** @param array<string, mixed>|SchemaType $schema */
    public function input(array|SchemaType $schema): self
    {
        if ($schema instanceof SchemaType) {
            $this->input = $schema->toArray();

            return $this;
        }

        if (! isset($schema['type'])) {
            $required = [];
            $properties = [];

            foreach ($schema as $name => $property) {
                if ($property instanceof SchemaType) {
                    $properties[$name] = $property->toArray();

                    if ($property->isRequired()) {
                        $required[] = $name;
                    }
                } else {
                    $properties[$name] = $property;
                }
            }

            $this->input = [
                'type' => 'object',
                'properties' => $properties,
                'required' => $required,
                'additionalProperties' => false,
            ];

            return $this;
        }

        $this->input = isset($schema['type'])
            ? $schema
            : ['type' => 'object', 'properties' => $schema];

        return $this;
    }

    public function target(ToolTarget $target): self
    {
        $this->target = $target;

        return $this;
    }

    /** @param class-string|callable $handler */
    public function handler(mixed $handler): self
    {
        $this->handler = $handler;

        return $this;
    }

    /** @param class-string|callable $authorizer */
    public function authorize(mixed $authorizer): self
    {
        $this->authorizer = $authorizer;

        return $this;
    }

    public function confirmation(string|Confirmation $policy): self
    {
        $this->confirmation = (string) $policy;

        return $this;
    }

    public function descriptionText(): string
    {
        return $this->description;
    }

    /** @return array<string, mixed> */
    public function schema(): array
    {
        return $this->input;
    }

    public function targetType(): ToolTarget
    {
        return $this->target;
    }

    public function handlerReference(): mixed
    {
        return $this->handler;
    }

    public function authorizer(): mixed
    {
        return $this->authorizer;
    }

    public function confirmationPolicy(): string
    {
        return $this->confirmation;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'description' => $this->description,
            'input_schema' => $this->input,
            'target' => $this->target->value,
            'confirmation' => $this->confirmation,
        ];
    }

    /** @return array<string, mixed> */
    public function toPersistentArray(): array
    {
        if (($this->handler !== null && ! is_string($this->handler))
            || ($this->authorizer !== null && ! is_string($this->authorizer))) {
            throw new \LogicException('Persisted agent definitions require class-string handlers and authorizers.');
        }

        return [
            ...$this->toArray(),
            'handler' => $this->handler,
            'authorizer' => $this->authorizer,
        ];
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
