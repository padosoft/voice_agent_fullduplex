<?php

declare(strict_types=1);

namespace AgentsFullDuplex\RealtimeAgent\Data;

use JsonSerializable;

final readonly class UsageRecord implements JsonSerializable
{
    /**
     * @param  array<string, int|float>  $units
     * @param  array<string, mixed>  $raw
     * @param  array<string, mixed>|null  $pricing
     */
    public function __construct(
        public string $id,
        public string $sessionId,
        public int $sequence,
        public string $provider,
        public ?string $providerEventId,
        public string $kind,
        public ?string $model,
        public array $units,
        public array $raw,
        public ?array $pricing,
        public ?string $amount,
        public string $currency,
        public string $status,
        public string $occurredAt,
    ) {}

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return [
            'id' => $this->id,
            'session_id' => $this->sessionId,
            'seq' => $this->sequence,
            'provider' => $this->provider,
            'provider_event_id' => $this->providerEventId,
            'kind' => $this->kind,
            'model' => $this->model,
            'units' => $this->units,
            'raw' => $this->raw,
            'pricing' => $this->pricing,
            'amount' => $this->amount,
            'currency' => $this->currency,
            'status' => $this->status,
            'occurred_at' => $this->occurredAt,
        ];
    }
}
