<?php

declare(strict_types=1);

namespace AgentsFullDuplex\RealtimeAgent\Engine;

use AgentsFullDuplex\RealtimeAgent\Contracts\ConversationStoreContract;
use AgentsFullDuplex\RealtimeAgent\Contracts\EventStoreContract;
use AgentsFullDuplex\RealtimeAgent\Contracts\ToolCallStoreContract;
use AgentsFullDuplex\RealtimeAgent\Contracts\UsageStoreContract;
use AgentsFullDuplex\RealtimeAgent\Data\AgentSession;
use AgentsFullDuplex\RealtimeAgent\Data\ConversationMessage;
use AgentsFullDuplex\RealtimeAgent\Data\SessionAudit;
use AgentsFullDuplex\RealtimeAgent\Data\UsageRecord;
use AgentsFullDuplex\RealtimeAgent\Events\AgentMessageRecorded;
use AgentsFullDuplex\RealtimeAgent\Events\AgentUsageRecorded;
use Illuminate\Contracts\Events\Dispatcher;

final readonly class SessionAuditManager
{
    /** @var list<string> */
    private const USAGE_UNITS = [
        'input_text_tokens',
        'cached_input_text_tokens',
        'input_audio_tokens',
        'cached_input_audio_tokens',
        'input_image_tokens',
        'cached_input_image_tokens',
        'output_text_tokens',
        'output_audio_tokens',
        'duration_seconds',
        'messages',
        'input_audio_seconds',
        'output_audio_seconds',
        'text_input_messages',
    ];

    public function __construct(
        private ConversationStoreContract $messages,
        private UsageStoreContract $usage,
        private ToolCallStoreContract $tools,
        private EventStoreContract $events,
        private UsageCostCalculator $costs,
        private Dispatcher $dispatcher,
    ) {}

    /** @param array<string, mixed> $data */
    public function recordMessage(AgentSession $session, array $data): ConversationMessage
    {
        $provider = (string) ($data['provider'] ?? $session->definition->provider);

        if ($provider !== $session->definition->provider) {
            throw new \InvalidArgumentException('The message provider does not match the session provider.');
        }

        $message = $this->messages->record($session->id, [
            'provider' => $provider,
            'provider_event_id' => $data['provider_event_id'] ?? null,
            'role' => (string) $data['role'],
            'direction' => (string) $data['direction'],
            'modality' => (string) $data['modality'],
            'status' => (string) ($data['status'] ?? 'completed'),
            'content' => (string) $data['content'],
            'metadata' => (array) ($data['metadata'] ?? []),
            'occurred_at' => (string) ($data['occurred_at'] ?? now()->toISOString()),
            'idempotency_key' => (string) $data['idempotency_key'],
        ]);

        $this->events->append(
            $session->id,
            'conversation.message.recorded',
            $message->role === 'user' && $message->modality === 'text' ? 'user' : 'provider',
            $session->state()->revision(),
            ['message' => $message->jsonSerialize()],
            $message->providerEventId,
        );
        $this->dispatcher->dispatch(new AgentMessageRecorded($session, $message));

        return $message;
    }

    /** @param array<string, mixed> $data */
    public function recordUsage(AgentSession $session, array $data): UsageRecord
    {
        $provider = (string) ($data['provider'] ?? $session->definition->provider);

        if ($provider !== $session->definition->provider) {
            throw new \InvalidArgumentException('The usage provider does not match the session provider.');
        }

        $units = [];

        foreach ((array) ($data['units'] ?? []) as $unit => $value) {
            if (! in_array($unit, self::USAGE_UNITS, true) || ! is_numeric($value) || (float) $value < 0) {
                throw new \InvalidArgumentException("Unsupported or invalid usage unit [{$unit}].");
            }

            $units[$unit] = is_int($value) ? $value : (float) $value;
        }

        $estimate = $this->costs->estimate($provider, isset($data['model']) ? (string) $data['model'] : null, $units);
        $record = $this->usage->record($session->id, [
            'provider' => $provider,
            'provider_event_id' => $data['provider_event_id'] ?? null,
            'kind' => (string) $data['kind'],
            'model' => $data['model'] ?? null,
            'units' => $units,
            'raw' => (array) ($data['raw'] ?? []),
            'pricing' => $estimate['pricing'],
            'amount' => $estimate['amount'],
            'currency' => $estimate['currency'],
            'status' => $estimate['status'],
            'occurred_at' => (string) ($data['occurred_at'] ?? now()->toISOString()),
            'idempotency_key' => (string) $data['idempotency_key'],
        ]);

        return $this->publishUsage($session, $record);
    }

    /**
     * Record a provider-confirmed amount from a trusted server-side API or webhook.
     *
     * @param  array<string, mixed>  $raw
     */
    public function recordProviderCost(
        AgentSession $session,
        string $providerEventId,
        string $amount,
        string $currency = 'USD',
        array $raw = [],
    ): UsageRecord {
        if (! is_numeric($amount) || (float) $amount < 0) {
            throw new \InvalidArgumentException('Provider cost must be a non-negative decimal amount.');
        }

        $currency = strtoupper($currency);

        if (preg_match('/^[A-Z]{3}$/', $currency) !== 1) {
            throw new \InvalidArgumentException('Provider cost currency must be a three-letter ISO code.');
        }

        $record = $this->usage->record($session->id, [
            'provider' => $session->definition->provider,
            'provider_event_id' => $providerEventId,
            'kind' => 'provider_invoice',
            'model' => null,
            'units' => [],
            'raw' => $raw,
            'pricing' => [
                'source' => 'provider_reported',
                'captured_at' => now()->toISOString(),
            ],
            'amount' => number_format((float) $amount, 8, '.', ''),
            'currency' => $currency,
            'status' => 'final',
            'occurred_at' => now()->toISOString(),
            'idempotency_key' => "provider-cost:{$providerEventId}",
        ]);

        return $this->publishUsage($session, $record);
    }

    public function forSession(AgentSession $session): SessionAudit
    {
        $usage = $this->usage->all($session->id);
        $estimated = [];
        $final = [];
        $unpriced = 0;

        foreach ($usage as $record) {
            if ($record->amount === null) {
                $unpriced++;

                continue;
            }

            $bucket = $record->status === 'final' ? 'final' : 'estimated';

            if ($bucket === 'final') {
                $final[$record->currency] = ($final[$record->currency] ?? 0.0) + (float) $record->amount;
            } else {
                $estimated[$record->currency] = ($estimated[$record->currency] ?? 0.0) + (float) $record->amount;
            }
        }

        $format = static fn (array $values): array => array_map(
            static fn (float $amount): string => number_format($amount, 8, '.', ''),
            $values,
        );
        $effective = $estimated;

        foreach ($final as $currency => $amount) {
            $effective[$currency] = $amount;
        }

        return new SessionAudit(
            sessionId: $session->id,
            provider: $session->definition->provider,
            providerSessionId: $session->providerSessionId(),
            messages: $this->messages->all($session->id),
            usage: $usage,
            toolCalls: $this->tools->allForSession($session->id),
            totals: [
                'estimated' => $format($estimated),
                'final' => $format($final),
                'effective' => $format($effective),
                'unpriced_records' => $unpriced,
            ],
        );
    }

    private function publishUsage(AgentSession $session, UsageRecord $record): UsageRecord
    {
        $this->events->append(
            $session->id,
            'provider.usage.recorded',
            'provider',
            $session->state()->revision(),
            ['usage' => $record->jsonSerialize()],
            $record->providerEventId,
        );
        $this->dispatcher->dispatch(new AgentUsageRecorded($session, $record));

        return $record;
    }
}
