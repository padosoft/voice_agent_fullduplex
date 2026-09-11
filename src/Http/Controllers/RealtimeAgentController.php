<?php

declare(strict_types=1);

namespace AgentsFullDuplex\RealtimeAgent\Http\Controllers;

use AgentsFullDuplex\RealtimeAgent\Data\AgentSession;
use AgentsFullDuplex\RealtimeAgent\Data\ToolCall;
use AgentsFullDuplex\RealtimeAgent\Data\ToolResult;
use AgentsFullDuplex\RealtimeAgent\Engine\AgentSessionManager;
use AgentsFullDuplex\RealtimeAgent\Engine\ConfirmationEngine;
use AgentsFullDuplex\RealtimeAgent\Engine\SessionAuditManager;
use AgentsFullDuplex\RealtimeAgent\Engine\SessionAuditReconciler;
use AgentsFullDuplex\RealtimeAgent\Engine\StateEngine;
use AgentsFullDuplex\RealtimeAgent\Engine\SurfacePatchApplier;
use AgentsFullDuplex\RealtimeAgent\Exceptions\ExpiredUiCommand;
use AgentsFullDuplex\RealtimeAgent\Exceptions\RevisionConflict;
use AgentsFullDuplex\RealtimeAgent\Exceptions\ToolCallRejected;
use AgentsFullDuplex\RealtimeAgent\Http\PayloadGuard;
use AgentsFullDuplex\RealtimeAgent\Http\SessionAuthorizer;
use AgentsFullDuplex\RealtimeAgent\Providers\OpenAI\OpenAIRealtimeProvider;
use AgentsFullDuplex\RealtimeAgent\Providers\ProviderManager;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

final readonly class RealtimeAgentController
{
    public function __construct(
        private AgentSessionManager $sessions,
        private ProviderManager $providers,
        private StateEngine $states,
        private ConfirmationEngine $confirmations,
        private SessionAuthorizer $authorizer,
        private PayloadGuard $payloads,
        private SurfacePatchApplier $surfacePatches,
        private SessionAuditManager $audits,
        private SessionAuditReconciler $auditReconciler,
    ) {}

    public function state(Request $request, string $session): JsonResponse
    {
        return $this->run($request, $session, static fn (AgentSession $agent): array => [
            'state' => $agent->state()->toArray(),
        ]);
    }

    public function connect(Request $request, string $session): JsonResponse
    {
        $this->payloads->enforceSize($request->getContent());

        return $this->run($request, $session, function (AgentSession $agent) use ($request): array {
            $offer = $request->headers->contains('Content-Type', 'application/sdp')
                ? $request->getContent()
                : null;

            $descriptor = $this->providers
                ->driver($agent->definition->provider)
                ->connect($agent, $offer);
            $providerSessionId = $descriptor->connection['conversation_id'] ?? null;

            if (is_string($providerSessionId) && $providerSessionId !== '') {
                $agent->setProviderSessionId($providerSessionId);
            }

            return $descriptor->jsonSerialize();
        });
    }

    public function audit(Request $request, string $session): JsonResponse
    {
        return $this->run($request, $session, fn (AgentSession $agent): array => [
            'audit' => $this->audits->forSession($agent)->jsonSerialize(),
        ]);
    }

    public function text(Request $request, string $session): JsonResponse
    {
        $this->payloads->enforceSize($request->getContent());
        $validated = $request->validate([
            'type' => ['required', 'in:message,tool_result'],
            'message' => ['required_if:type,message', 'nullable', 'string', 'max:'.config('realtime-agent.audit.max_message_characters', 65_535)],
            'tool_result' => ['required_if:type,tool_result', 'nullable', 'array'],
            'tool_result.call_id' => ['required_if:type,tool_result', 'string', 'max:128'],
            'tool_result.status' => ['required_if:type,tool_result', 'string', 'max:64'],
            'tool_result.output' => ['nullable', 'array'],
            'tool_result.error' => ['nullable', 'array'],
            'tool_result.state_revision' => ['required_if:type,tool_result', 'integer', 'min:1'],
        ]);

        return $this->run($request, $session, function (AgentSession $agent) use ($validated): array {
            $provider = $this->providers->driver($agent->definition->provider);

            if (! $provider instanceof OpenAIRealtimeProvider) {
                throw new \LogicException('Server-side text continuation is available only for OpenAI GPT-Live sessions.');
            }

            return [
                'response' => $provider->respondText(
                    session: $agent,
                    message: $validated['type'] === 'message' ? (string) $validated['message'] : null,
                    toolResult: $validated['type'] === 'tool_result' ? (array) $validated['tool_result'] : null,
                ),
            ];
        });
    }

    public function reconcileAudit(Request $request, string $session): JsonResponse
    {
        return $this->run($request, $session, fn (AgentSession $agent): array => [
            'audit' => $this->auditReconciler->reconcile($agent)->jsonSerialize(),
        ]);
    }

    public function message(Request $request, string $session): JsonResponse
    {
        $this->payloads->enforceSize($request->getContent());
        $validated = $request->validate([
            'provider' => ['required', 'string', 'max:64'],
            'provider_event_id' => ['nullable', 'string', 'max:255'],
            'idempotency_key' => ['required', 'string', 'max:255'],
            'role' => ['required', 'in:user,assistant,system,tool'],
            'direction' => ['required', 'in:input,output,internal'],
            'modality' => ['required', 'in:text,audio,tool'],
            'status' => ['sometimes', 'in:completed,corrected,interrupted'],
            'content' => ['required', 'string', 'max:'.config('realtime-agent.audit.max_message_characters', 65_535)],
            'metadata' => ['sometimes', 'array'],
            'occurred_at' => ['nullable', 'date'],
        ]);

        return $this->run($request, $session, fn (AgentSession $agent): array => [
            'message' => $this->audits->recordMessage($agent, $validated)->jsonSerialize(),
        ]);
    }

    public function usage(Request $request, string $session): JsonResponse
    {
        $this->payloads->enforceSize($request->getContent());
        $validated = $request->validate([
            'provider' => ['required', 'string', 'max:64'],
            'provider_event_id' => ['nullable', 'string', 'max:255'],
            'idempotency_key' => ['required', 'string', 'max:255'],
            'kind' => ['required', 'in:response,transcription,duration,conversation'],
            'model' => ['nullable', 'string', 'max:128'],
            'units' => ['required', 'array', 'min:1', 'max:12'],
            'units.*' => ['numeric', 'min:0'],
            'raw' => ['sometimes', 'array'],
            'occurred_at' => ['nullable', 'date'],
        ]);

        return $this->run($request, $session, fn (AgentSession $agent): array => [
            'usage' => $this->audits->recordUsage($agent, $validated)->jsonSerialize(),
        ]);
    }

    public function surface(Request $request, string $session): JsonResponse
    {
        $this->payloads->enforceSize($request->getContent());
        $validated = $request->validate([
            'base_revision' => ['required', 'integer', 'min:1'],
            'snapshot' => ['required', 'array'],
        ]);
        $this->payloads->surface($validated['snapshot']);

        return $this->run($request, $session, fn (AgentSession $agent): array => [
            'state' => $this->states
                ->updateSurface($agent, (int) $validated['base_revision'], $validated['snapshot'])
                ->toArray(),
        ]);
    }

    public function tool(Request $request, string $session): JsonResponse
    {
        $this->payloads->enforceSize($request->getContent());
        $validated = $request->validate([
            'id' => ['nullable', 'string', 'max:128'],
            'name' => ['required', 'string', 'max:128'],
            'arguments' => ['sometimes', 'array'],
            'base_revision' => ['required', 'integer', 'min:1'],
            'idempotency_key' => ['required', 'string', 'max:255'],
            'provider_call_id' => ['nullable', 'string', 'max:255'],
        ]);

        return $this->run($request, $session, function (AgentSession $agent) use ($validated): array {
            $call = new ToolCall(
                id: (string) ($validated['id'] ?? 'call_'.Str::ulid()),
                name: $validated['name'],
                arguments: $validated['arguments'] ?? [],
                baseRevision: (int) $validated['base_revision'],
                idempotencyKey: $validated['idempotency_key'],
                providerCallId: $validated['provider_call_id'] ?? null,
                confirmed: false,
            );

            return ['result' => $agent->execute($call)->toArray()];
        });
    }

    public function patchSurface(Request $request, string $session): JsonResponse
    {
        $this->payloads->enforceSize($request->getContent());
        $validated = $request->validate([
            'base_revision' => ['required', 'integer', 'min:1'],
            'patches' => ['required', 'array', 'max:128'],
            'patches.*.op' => ['required', 'string'],
            'patches.*.path' => ['required', 'string', 'max:512'],
            'patches.*.value' => ['sometimes'],
        ]);

        return $this->run($request, $session, function (AgentSession $agent) use ($validated): array {
            $current = (array) $agent->state()->toArray()['environment']['ui'];
            $this->payloads->surface($this->surfacePatches->apply($current, $validated['patches']));

            return [
                'state' => $this->states
                    ->patchSurface($agent, (int) $validated['base_revision'], $validated['patches'])
                    ->toArray(),
            ];
        });
    }

    public function confirmation(Request $request, string $session, string $confirmation): JsonResponse
    {
        $validated = $request->validate(['accepted' => ['required', 'boolean']]);

        return $this->run($request, $session, function (AgentSession $agent) use ($confirmation, $validated): array {
            $call = $this->confirmations->resolve($agent, $confirmation, $validated['accepted']);

            if ($call === null) {
                return ['result' => (new ToolResult(
                    callId: $confirmation,
                    status: 'rejected',
                    output: null,
                    error: ['message' => 'The user rejected the tool call.'],
                    stateRevision: $agent->state()->revision(),
                ))->toArray()];
            }

            return ['result' => $agent->execute($call)->toArray()];
        });
    }

    public function completeCommand(Request $request, string $session, string $command): JsonResponse
    {
        $this->payloads->enforceSize($request->getContent());
        $validated = $request->validate([
            'base_revision' => ['required', 'integer', 'min:1'],
            'token' => ['required', 'string', 'size:64'],
            'result' => ['required', 'array'],
            'surface' => ['nullable', 'array'],
        ]);

        if (isset($validated['surface'])) {
            $this->payloads->surface($validated['surface']);
        }

        return $this->run($request, $session, fn (AgentSession $agent): array => [
            'state' => $this->states->completeUiCommand(
                session: $agent,
                baseRevision: (int) $validated['base_revision'],
                commandId: $command,
                token: $validated['token'],
                result: $validated['result'],
                surfaceSnapshot: $validated['surface'] ?? null,
            )->toArray(),
        ]);
    }

    public function finish(Request $request, string $session): JsonResponse
    {
        $validated = $request->validate(['base_revision' => ['required', 'integer', 'min:1']]);

        return $this->run($request, $session, fn (AgentSession $agent): array => [
            'state' => $this->states->finish($agent, (int) $validated['base_revision'])->toArray(),
        ]);
    }

    /** @param Closure(AgentSession): array<string, mixed> $operation */
    private function run(Request $request, string $sessionId, Closure $operation): JsonResponse
    {
        try {
            $session = $this->sessions->resume($sessionId);
            $this->authorizer->authorize($session, $request->user());

            return response()->json($operation($session));
        } catch (AuthorizationException $exception) {
            return $this->error($exception->getMessage(), Response::HTTP_FORBIDDEN);
        } catch (RevisionConflict $exception) {
            $state = $this->sessions->resume($sessionId)->state()->toArray();

            return response()->json([
                'error' => ['code' => 'stale_revision', 'message' => $exception->getMessage()],
                'state' => $state,
            ], Response::HTTP_CONFLICT);
        } catch (ExpiredUiCommand $exception) {
            return $this->error($exception->getMessage(), Response::HTTP_GONE, 'command_expired');
        } catch (ToolCallRejected $exception) {
            $status = str_contains(strtolower($exception->getMessage()), 'authorized')
                || str_contains(strtolower($exception->getMessage()), 'enabled')
                || str_contains(strtolower($exception->getMessage()), 'token')
                ? Response::HTTP_FORBIDDEN
                : Response::HTTP_UNPROCESSABLE_ENTITY;

            return $this->error($exception->getMessage(), $status, 'tool_rejected');
        } catch (\InvalidArgumentException $exception) {
            return $this->error($exception->getMessage(), Response::HTTP_UNPROCESSABLE_ENTITY, 'invalid_audit_payload');
        } catch (ValidationException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            report($exception);

            return $this->error('The realtime agent request failed.', Response::HTTP_BAD_GATEWAY, 'provider_error');
        }
    }

    private function error(string $message, int $status, string $code = 'forbidden'): JsonResponse
    {
        return response()->json(['error' => ['code' => $code, 'message' => $message]], $status);
    }
}
