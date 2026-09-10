<?php

declare(strict_types=1);

namespace AgentsFullDuplex\RealtimeAgent\Data;

use JsonSerializable;

final class SchemaType implements JsonSerializable
{
    /** @var array<string, mixed> */
    private array $constraints = [];

    private bool $required = false;

    private function __construct(private readonly string $type) {}

    public static function make(string $type): self
    {
        return new self($type);
    }

    public function required(bool $required = true): self
    {
        $this->required = $required;

        return $this;
    }

    /** @param list<string|int|float|bool> $values */
    public function enum(array $values): self
    {
        $this->constraints['enum'] = $values;

        return $this;
    }

    public function description(string $description): self
    {
        $this->constraints['description'] = $description;

        return $this;
    }

    /** @param array<string, mixed>|null $items */
    public function items(self|array|null $items): self
    {
        if ($items !== null) {
            $this->constraints['items'] = $items instanceof self ? $items->toArray() : $items;
        }

        return $this;
    }

    /** @param array<string, self|array<string, mixed>> $properties */
    public function properties(array $properties): self
    {
        $required = [];
        $normalized = [];

        foreach ($properties as $name => $property) {
            if ($property instanceof self) {
                $normalized[$name] = $property->toArray();

                if ($property->isRequired()) {
                    $required[] = $name;
                }
            } else {
                $normalized[$name] = $property;
            }
        }

        $this->constraints['properties'] = $normalized;

        if ($required !== []) {
            $this->constraints['required'] = $required;
        }

        $this->constraints['additionalProperties'] = false;

        return $this;
    }

    public function isRequired(): bool
    {
        return $this->required;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return ['type' => $this->type, ...$this->constraints];
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
