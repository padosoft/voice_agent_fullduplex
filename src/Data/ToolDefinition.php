<?php

declare(strict_types=1);

namespace AgentsFullDuplex\RealtimeAgent\Data;

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

    private function __construct(private readonly string $name)
    {
    }

    public static function make(string $name): self
    {
        return new self($name);
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

    /** @param array<string, mixed> $schema */
    public function input(array $schema): self
    {
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

    public function confirmation(string $policy): self
    {
        $this->confirmation = $policy;

        return $this;
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
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
