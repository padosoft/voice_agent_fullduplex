<?php

declare(strict_types=1);

namespace AgentsFullDuplex\RealtimeAgent\Data;

use JsonSerializable;

final readonly class SessionAudit implements JsonSerializable
{
    /**
     * @param  list<ConversationMessage>  $messages
     * @param  list<UsageRecord>  $usage
     * @param  list<array<string, mixed>>  $toolCalls
     * @param  array<string, mixed>  $totals
     */
    public function __construct(
        public string $sessionId,
        public string $provider,
        public ?string $providerSessionId,
        public array $messages,
        public array $usage,
        public array $toolCalls,
        public array $totals,
    ) {}

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return [
            'schema' => 'realtime-agent-audit@1',
            'session_id' => $this->sessionId,
            'provider' => $this->provider,
            'provider_session_id' => $this->providerSessionId,
            'messages' => $this->messages,
            'usage' => $this->usage,
            'tool_calls' => $this->toolCalls,
            'totals' => $this->totals,
        ];
    }
}
