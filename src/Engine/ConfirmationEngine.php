<?php

declare(strict_types=1);

namespace AgentsFullDuplex\RealtimeAgent\Engine;

use AgentsFullDuplex\RealtimeAgent\Contracts\EventStoreContract;
use AgentsFullDuplex\RealtimeAgent\Contracts\StateStoreContract;
use AgentsFullDuplex\RealtimeAgent\Data\AgentSession;
use AgentsFullDuplex\RealtimeAgent\Data\AgentState;
use AgentsFullDuplex\RealtimeAgent\Data\ToolCall;
use AgentsFullDuplex\RealtimeAgent\Data\ToolResult;
use AgentsFullDuplex\RealtimeAgent\Engine\Mutations\ReplaceState;
use AgentsFullDuplex\RealtimeAgent\Exceptions\ToolCallRejected;
use Illuminate\Support\Str;

final readonly class ConfirmationEngine
{
    public function __construct(
        private StateStoreContract $states,
        private EventStoreContract $events,
    ) {}

    public function request(AgentSession $session, ToolCall $call): ToolResult
    {
        foreach ($session->state()->toArray()['pending']['confirmations'] ?? [] as $pending) {
            if (($pending['call']['idempotency_key'] ?? null) === $call->idempotencyKey) {
                return new ToolResult(
                    callId: $call->id,
                    status: 'confirmation_required',
                    output: ['confirmation' => $pending],
                    error: null,
                    stateRevision: $session->state()->revision(),
                );
            }
        }

        $confirmation = [
            'id' => 'cnf_'.Str::ulid(),
            'call' => $call->toArray(),
            'requested_at' => now()->toISOString(),
        ];

        $next = $this->states->mutate(
            $session->id,
            $call->baseRevision,
            new ReplaceState(static function (AgentState $current) use ($confirmation): array {
                $state = $current->toArray();
                $state['pending']['confirmations'][] = $confirmation;

                return $state;
            }),
        );

        $this->events->append(
            $session->id,
            'confirmation.requested',
            'engine',
            $next->revision(),
            ['confirmation_id' => $confirmation['id'], 'call_id' => $call->id, 'tool' => $call->name],
        );

        return new ToolResult(
            callId: $call->id,
            status: 'confirmation_required',
            output: ['confirmation' => $confirmation],
            error: null,
            stateRevision: $next->revision(),
        );
    }

    public function resolve(AgentSession $session, string $confirmationId, bool $accepted): ?ToolCall
    {
        $found = null;
        $current = $session->state();
        $next = $this->states->mutate(
            $session->id,
            $current->revision(),
            new ReplaceState(static function (AgentState $state) use ($confirmationId, &$found): array {
                $data = $state->toArray();
                $remaining = [];

                foreach ($data['pending']['confirmations'] ?? [] as $confirmation) {
                    if (($confirmation['id'] ?? null) === $confirmationId) {
                        $found = $confirmation;

                        continue;
                    }

                    $remaining[] = $confirmation;
                }

                $data['pending']['confirmations'] = $remaining;

                return $data;
            }),
        );

        if (! is_array($found) || ! isset($found['call']) || ! is_array($found['call'])) {
            throw new ToolCallRejected('The confirmation request does not exist or was already resolved.');
        }

        $call = ToolCall::fromArray($found['call'], confirmed: $accepted);
        $this->events->append(
            $session->id,
            $accepted ? 'confirmation.accepted' : 'confirmation.rejected',
            'user',
            $next->revision(),
            ['confirmation_id' => $confirmationId, 'call_id' => $call->id, 'tool' => $call->name],
        );

        if (! $accepted) {
            return null;
        }

        return new ToolCall(
            id: $call->id,
            name: $call->name,
            arguments: $call->arguments,
            baseRevision: $next->revision(),
            idempotencyKey: $call->idempotencyKey,
            providerCallId: $call->providerCallId,
            confirmed: true,
        );
    }
}
