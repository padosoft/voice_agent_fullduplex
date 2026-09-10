<?php

declare(strict_types=1);

namespace AgentsFullDuplex\RealtimeAgent\Engine;

use AgentsFullDuplex\RealtimeAgent\Contracts\EventStoreContract;
use AgentsFullDuplex\RealtimeAgent\Contracts\ToolBrokerContract;
use AgentsFullDuplex\RealtimeAgent\Contracts\ToolCallStoreContract;
use AgentsFullDuplex\RealtimeAgent\Contracts\ToolHandlerContract;
use AgentsFullDuplex\RealtimeAgent\Data\AgentSession;
use AgentsFullDuplex\RealtimeAgent\Data\ToolCall;
use AgentsFullDuplex\RealtimeAgent\Data\ToolResult;
use AgentsFullDuplex\RealtimeAgent\Enums\ToolTarget;
use AgentsFullDuplex\RealtimeAgent\Events\AgentToolCalled;
use AgentsFullDuplex\RealtimeAgent\Events\AgentToolFailed;
use AgentsFullDuplex\RealtimeAgent\Exceptions\ToolCallRejected;
use Illuminate\Cache\RateLimiter;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Events\Dispatcher;
use Throwable;

final class ToolBroker implements ToolBrokerContract
{
    public function __construct(
        private readonly Container $container,
        private readonly ToolRegistry $registry,
        private readonly JsonSchemaValidator $validator,
        private readonly ConfirmationEngine $confirmations,
        private readonly EventStoreContract $events,
        private readonly ToolCallStoreContract $calls,
        private readonly Dispatcher $dispatcher,
        private readonly RateLimiter $limiter,
    ) {}

    public function execute(AgentSession $session, ToolCall $call): ToolResult
    {
        $rateKey = 'realtime-agent:tool:'.hash('sha256', $session->id.':'.$call->name);
        $limit = (int) config('realtime-agent.security.max_tool_calls_per_minute', 120);

        if ($this->limiter->tooManyAttempts($rateKey, $limit)) {
            throw new ToolCallRejected("Tool {$call->name} exceeded its session rate limit.");
        }

        $this->limiter->hit($rateKey, 60);

        if ($completed = $this->calls->completed($session->id, $call->idempotencyKey)) {
            return $completed;
        }

        $tools = $this->registry->forDefinition($session->definition);
        $definition = $tools[$call->name]
            ?? throw new ToolCallRejected("Tool {$call->name} is not enabled for this session.");

        $this->validator->validate($definition->schema(), $call->arguments);
        $this->authorize($definition->authorizer(), $session, $call);

        if ($definition->confirmationPolicy() !== 'never' && ! $call->confirmed) {
            return $this->confirmations->request($session, $call);
        }

        if (! $this->calls->begin($session->id, $call)) {
            if ($completed = $this->calls->completed($session->id, $call->idempotencyKey)) {
                return $completed;
            }

            throw new ToolCallRejected("Tool call {$call->id} is already being processed.");
        }

        $this->events->append(
            $session->id,
            'tool.started',
            'agent',
            $session->state()->revision(),
            ['call_id' => $call->id, 'tool' => $call->name],
        );

        try {
            $handler = $definition->handlerReference();

            if ($handler === null) {
                throw new ToolCallRejected("Tool {$call->name} has no handler.");
            }

            $handler = is_string($handler) ? $this->container->make($handler) : $handler;
            $output = $handler instanceof ToolHandlerContract
                ? $handler->handle($session, $call)
                : $handler($session, $call);

            $result = new ToolResult(
                callId: $call->id,
                status: $definition->targetType() === ToolTarget::Ui ? 'awaiting_client' : 'completed',
                output: $output,
                error: null,
                stateRevision: $session->state()->revision(),
            );

            $this->events->append(
                $session->id,
                'tool.completed',
                'engine',
                $result->stateRevision,
                ['call_id' => $call->id, 'tool' => $call->name],
            );

            $this->calls->succeed($session->id, $call, $result);
            $this->dispatcher->dispatch(new AgentToolCalled($session->id, $call, $result));

            return $result;
        } catch (Throwable $exception) {
            $this->calls->fail($session->id, $call, $exception);
            $this->dispatcher->dispatch(new AgentToolFailed($session->id, $call, $exception));
            $this->events->append(
                $session->id,
                'tool.failed',
                'engine',
                $session->state()->revision(),
                ['call_id' => $call->id, 'tool' => $call->name, 'error' => $exception->getMessage()],
            );

            throw $exception;
        }
    }

    private function authorize(mixed $authorizer, AgentSession $session, ToolCall $call): void
    {
        if ($authorizer === null) {
            return;
        }

        $authorizer = is_string($authorizer) ? $this->container->make($authorizer) : $authorizer;
        $allowed = is_callable($authorizer)
            ? $authorizer($session->owner, $call, $session)
            : $authorizer->authorize($session->owner, $call, $session);

        if ($allowed !== true) {
            throw new ToolCallRejected("Tool {$call->name} is not authorized.");
        }
    }
}
