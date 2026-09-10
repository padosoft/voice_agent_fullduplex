<?php

declare(strict_types=1);

namespace AgentsFullDuplex\RealtimeAgent\Data;

use JsonSerializable;

final readonly class ToolResult implements JsonSerializable
{
    /**
     * @param  array<string, mixed>|null  $output
     * @param  array<string, mixed>|null  $error
     */
    public function __construct(
        public string $callId,
        public string $status,
        public ?array $output,
        public ?array $error,
        public int $stateRevision,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'call_id' => $this->callId,
            'status' => $this->status,
            'output' => $this->output,
            'error' => $this->error,
            'state_revision' => $this->stateRevision,
        ];
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
