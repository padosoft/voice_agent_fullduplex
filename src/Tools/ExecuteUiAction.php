<?php

declare(strict_types=1);

namespace AgentsFullDuplex\RealtimeAgent\Tools;

use AgentsFullDuplex\RealtimeAgent\Contracts\ToolContract;
use AgentsFullDuplex\RealtimeAgent\Contracts\ToolHandlerContract;
use AgentsFullDuplex\RealtimeAgent\Data\AgentSession;
use AgentsFullDuplex\RealtimeAgent\Data\ToolCall;
use AgentsFullDuplex\RealtimeAgent\Data\ToolDefinition;
use AgentsFullDuplex\RealtimeAgent\Data\UiCommand;
use AgentsFullDuplex\RealtimeAgent\Engine\StateEngine;
use AgentsFullDuplex\RealtimeAgent\Enums\InteractionLevel;
use AgentsFullDuplex\RealtimeAgent\Enums\ToolTarget;
use AgentsFullDuplex\RealtimeAgent\Exceptions\ToolCallRejected;
use AgentsFullDuplex\RealtimeAgent\Tool;
use Illuminate\Support\Str;

final readonly class ExecuteUiAction implements ToolContract, ToolHandlerContract
{
    public function __construct(private StateEngine $states)
    {
    }

    public function definition(): ToolDefinition
    {
        return Tool::make('ui.action')
            ->description('Request a registered semantic action on the current UI Surface.')
            ->input([
                'type' => 'object',
                'properties' => [
                    'surface' => ['type' => 'string'],
                    'action' => ['type' => 'string'],
                    'target' => ['type' => 'string'],
                    'arguments' => ['type' => 'object'],
                ],
                'required' => ['surface', 'action', 'target'],
                'additionalProperties' => false,
            ])
            ->target(ToolTarget::Ui)
            ->handler(self::class);
    }

    public function handle(AgentSession $session, ToolCall $call): array
    {
        if ($session->definition->interactionLevel === InteractionLevel::Observe) {
            throw new ToolCallRejected('Observe-only sessions cannot request UI actions.');
        }

        $state = $session->state()->toArray();
        $ui = $state['environment']['ui'];

        if (($ui['surface'] ?? null) !== $call->arguments['surface']) {
            throw new ToolCallRejected('The requested Surface is not current.');
        }

        $components = (array) ($ui['components'] ?? []);
        $component = $components[$call->arguments['target']] ?? null;

        if ($component === null) {
            throw new ToolCallRejected('The requested semantic target is not exposed.');
        }

        if (! in_array($call->arguments['action'], $component['actions'] ?? [], true)) {
            throw new ToolCallRejected('The requested action is not registered for this target.');
        }

        $command = new UiCommand(
            id: 'cmd_'.Str::ulid(),
            sessionId: $session->id,
            callId: $call->id,
            baseRevision: $call->baseRevision,
            surface: $call->arguments['surface'],
            action: $call->arguments['action'],
            target: $call->arguments['target'],
            arguments: $call->arguments['arguments'] ?? [],
            expiresAt: now()->addSeconds((int) config('realtime-agent.security.ui_command_ttl_seconds', 15))->toISOString(),
            nonce: Str::random(40),
        );

        $next = $this->states->queueUiCommand($session, $call->baseRevision, $command);

        return ['command' => $command->jsonSerialize(), 'revision' => $next->revision()];
    }
}
