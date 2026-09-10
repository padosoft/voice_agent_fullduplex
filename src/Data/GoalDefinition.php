<?php

declare(strict_types=1);

namespace AgentsFullDuplex\RealtimeAgent\Data;

use AgentsFullDuplex\RealtimeAgent\Enums\GoalStatus;
use JsonSerializable;

final class GoalDefinition implements JsonSerializable
{
    private string $label;

    private ?string $description = null;

    private bool $required = false;

    private GoalStatus $status = GoalStatus::Pending;

    private string $source = 'application';

    /** @var array{mode: string, requires_evidence: bool} */
    private array $completion = [
        'mode' => 'agent_judgement',
        'requires_evidence' => false,
    ];

    private function __construct(private readonly string $id)
    {
        $this->label = $id;
    }

    public static function make(string $id): self
    {
        return new self($id);
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        $goal = new self((string) $data['id']);
        $goal->label = (string) ($data['label'] ?? $data['id']);
        $goal->description = isset($data['description']) ? (string) $data['description'] : null;
        $goal->required = (bool) ($data['required'] ?? false);
        $goal->status = GoalStatus::from((string) ($data['status'] ?? GoalStatus::Pending->value));
        $goal->source = (string) ($data['source'] ?? 'application');
        $goal->completion = array_replace($goal->completion, (array) ($data['completion'] ?? []));

        return $goal;
    }

    public function label(string $label): self
    {
        $this->label = $label;

        return $this;
    }

    public function description(string $description): self
    {
        $this->description = $description;

        return $this;
    }

    public function required(bool $required = true): self
    {
        $this->required = $required;

        return $this;
    }

    public function status(GoalStatus $status): self
    {
        $this->status = $status;

        return $this;
    }

    public function source(string $source): self
    {
        $this->source = $source;

        return $this;
    }

    public function completionByAgent(bool $requiresEvidence = false): self
    {
        $this->completion = [
            'mode' => 'agent_judgement',
            'requires_evidence' => $requiresEvidence,
        ];

        return $this;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'label' => $this->label,
            'description' => $this->description,
            'status' => $this->status->value,
            'required' => $this->required,
            'source' => $this->source,
            'completion' => $this->completion,
            'evidence' => null,
            'created_at' => null,
            'completed_at' => null,
        ];
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
