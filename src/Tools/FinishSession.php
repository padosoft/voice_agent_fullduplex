<?php

declare(strict_types=1);

namespace AgentsFullDuplex\RealtimeAgent\Tools;

use AgentsFullDuplex\RealtimeAgent\Contracts\FinishPolicyContract;
use AgentsFullDuplex\RealtimeAgent\Contracts\ToolContract;
use AgentsFullDuplex\RealtimeAgent\Contracts\ToolHandlerContract;
use AgentsFullDuplex\RealtimeAgent\Data\AgentSession;
use AgentsFullDuplex\RealtimeAgent\Data\ToolCall;
use AgentsFullDuplex\RealtimeAgent\Data\ToolDefinition;
use AgentsFullDuplex\RealtimeAgent\Engine\StateEngine;
use AgentsFullDuplex\RealtimeAgent\Enums\ToolTarget;
use AgentsFullDuplex\RealtimeAgent\Exceptions\ToolCallRejected;
use AgentsFullDuplex\RealtimeAgent\Tool;
use Illuminate\Contracts\Container\Container;

final readonly class FinishSession implements ToolContract, ToolHandlerContract
{
    public function __construct(
        private StateEngine $states,
        private Container $container,
    ) {
    }

    public function definition(): ToolDefinition
    {
        return Tool::make('session.finish')
            ->description('Finish the session if its application completion policy is satisfied.')
            ->input(['type' => 'object', 'properties' => [], 'additionalProperties' => false])
            ->target(ToolTarget::State)
            ->handler(self::class);
    }

    public function handle(AgentSession $session, ToolCall $call): array
    {
        $policy = $this->container->make($session->definition->finishPolicy);

        if (! $policy instanceof FinishPolicyContract || ! $policy->shouldFinish($session->state())) {
            throw new ToolCallRejected('The session completion policy is not satisfied.');
        }

        $state = $session->state()->status() === 'completed'
            ? $session->state()
            : $this->states->finish($session, $call->baseRevision);

        return ['status' => $state->status(), 'revision' => $state->revision()];
    }
}
