<?php

declare(strict_types=1);

namespace AgentsFullDuplex\RealtimeAgent\Tests\Feature;

use AgentsFullDuplex\RealtimeAgent\Contracts\EventStoreContract;
use AgentsFullDuplex\RealtimeAgent\Data\ToolCall;
use AgentsFullDuplex\RealtimeAgent\Engine\AgentSessionManager;
use AgentsFullDuplex\RealtimeAgent\Models\ProviderToolRecord;
use AgentsFullDuplex\RealtimeAgent\Providers\Fake\FakeRealtimeProvider;
use AgentsFullDuplex\RealtimeAgent\Tests\TestCase;
use AgentsFullDuplex\RealtimeAgent\Tools\RuntimeStateGet;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

final class ProviderBootstrapTest extends TestCase
{
    public function test_fake_is_default_and_never_needs_credentials_or_http(): void
    {
        Http::fake();

        $session = $this->app->make(AgentSessionManager::class)
            ->make('provider.fake')
            ->startFor(null);

        self::assertSame('fake', $session->connection()->provider);
        self::assertSame('fake', $session->connection()->connection['transport']);
        self::assertSame([null], $this->app->make(FakeRealtimeProvider::class)->simulate($session, [
            ['transcript' => 'Deterministic hello.'],
        ]));
        self::assertContains(
            'agent.transcript.final',
            array_column($this->app->make(EventStoreContract::class)->forSession($session->id), 'type'),
        );
        Http::assertNothingSent();
    }

    public function test_doctor_reports_missing_gpt_live_configuration_without_contacting_openai(): void
    {
        config()->set('realtime-agent.default', 'openai');
        config()->set('realtime-agent.providers.openai.api_key');
        Http::fake();

        $this->artisan('realtime-agent:doctor')
            ->expectsOutputToContain('missing api_key')
            ->assertFailed();

        Http::assertNothingSent();
    }

    public function test_openai_gpt_live_session_is_created_with_responses_delegation_and_fake_http(): void
    {
        config()->set('realtime-agent.providers.openai.api_key', 'test-key');
        config()->set('realtime-agent.providers.openai.model', 'gpt-live-1');
        config()->set('realtime-agent.providers.openai.backend_model', 'gpt-5.6-terra');
        Http::fake([
            '*/live/sessions' => Http::response([
                'session' => ['id' => 'live_1'],
                'transport' => ['type' => 'webrtc', 'sdp' => 'v=0\r\no=answer'],
            ], 201),
        ]);
        $session = $this->app->make(AgentSessionManager::class)
            ->make('provider.openai')
            ->provider('openai')
            ->instructions('Teach safely.')
            ->tools([RuntimeStateGet::class])
            ->startFor(null);

        $this->call(
            'POST',
            "/realtime-agent/sessions/{$session->id}/connect",
            server: ['CONTENT_TYPE' => 'application/sdp', 'HTTP_ACCEPT' => 'application/json'],
            content: 'v=0\r\no=offer',
        )->assertOk()
            ->assertJsonPath('provider', 'openai')
            ->assertJsonPath('connection.api_variant', 'live')
            ->assertJsonPath('connection.answer_sdp', 'v=0\r\no=answer')
            ->assertJsonPath('connection.provider_session_id', 'live_1')
            ->assertJsonPath('connection.backend_model', 'gpt-5.6-terra')
            ->assertJsonPath('connection.tool_name_map.runtime_state_get', 'runtime.state.get');

        Http::assertSent(static fn (Request $request): bool => $request->url() === 'https://api.openai.com/v1/live/sessions'
            && $request->hasHeader('Authorization', 'Bearer test-key')
            && $request->hasHeader('OpenAI-Safety-Identifier')
            && $request->data()['transport'] === ['type' => 'webrtc', 'sdp' => 'v=0\r\no=offer']
            && data_get($request->data(), 'session.model') === 'gpt-live-1'
            && data_get($request->data(), 'session.delegation.type') === 'responses'
            && data_get($request->data(), 'session.delegation.responses.model') === 'gpt-5.6-terra'
            && data_get($request->data(), 'session.delegation.responses.parallel_tool_calls') === false
            && data_get($request->data(), 'session.delegation.responses.tools.0.name') === 'runtime_state_get'
            && in_array(
                ['type' => 'response.event', 'response_event' => 'response.output_item.done'],
                data_get($request->data(), 'session.client.data_channel.allowed_server_events'),
                true,
            )
            && in_array('session.close', data_get($request->data(), 'session.client.data_channel.allowed_client_events'), true)
            && data_get($request->data(), 'session.store') === false
        );
        self::assertSame('live_1', $session->providerSessionId());
    }

    public function test_openai_gpt_live_reconnect_seeds_saved_text_history(): void
    {
        config()->set('realtime-agent.providers.openai.api_key', 'test-key');
        Http::fake([
            '*/live/sessions' => Http::response([
                'session' => ['id' => 'live_reconnected'],
                'transport' => ['type' => 'webrtc', 'sdp' => 'answer'],
            ], 201),
        ]);
        $session = $this->app->make(AgentSessionManager::class)
            ->make('provider.openai.history')
            ->provider('openai')
            ->startFor(null);
        $session->recordMessage([
            'provider' => 'openai',
            'provider_event_id' => 'message_user',
            'idempotency_key' => 'message_user',
            'role' => 'user',
            'direction' => 'input',
            'modality' => 'text',
            'content' => 'Continue from here.',
        ]);
        $session->recordMessage([
            'provider' => 'openai',
            'provider_event_id' => 'message_agent',
            'idempotency_key' => 'message_agent',
            'role' => 'assistant',
            'direction' => 'output',
            'modality' => 'text',
            'content' => 'I remember the context.',
        ]);

        $this->call(
            'POST',
            "/realtime-agent/sessions/{$session->id}/connect",
            server: ['CONTENT_TYPE' => 'application/sdp', 'HTTP_ACCEPT' => 'application/json'],
            content: 'offer',
        )->assertOk();

        Http::assertSent(static fn (Request $request): bool => data_get($request->data(), 'session.input') === [
            [
                'type' => 'message',
                'role' => 'user',
                'content' => [['type' => 'input_text', 'text' => 'Continue from here.']],
            ],
            [
                'type' => 'message',
                'role' => 'assistant',
                'content' => [['type' => 'output_text', 'text' => 'I remember the context.']],
            ],
        ]);
    }

    public function test_openai_text_continuation_uses_responses_and_records_message_and_cost(): void
    {
        config()->set('realtime-agent.providers.openai.api_key', 'test-key');
        Http::fake([
            '*/responses' => Http::response([
                'id' => 'resp_text_1',
                'model' => 'gpt-5.6-terra',
                'output' => [[
                    'id' => 'msg_text_1',
                    'type' => 'message',
                    'role' => 'assistant',
                    'content' => [[
                        'type' => 'output_text',
                        'text' => 'Here is the written continuation.',
                    ]],
                ]],
                'usage' => [
                    'input_tokens' => 10,
                    'output_tokens' => 20,
                    'input_tokens_details' => ['cached_tokens' => 0],
                ],
            ]),
        ]);
        $session = $this->app->make(AgentSessionManager::class)
            ->make('provider.openai.text')
            ->provider('openai')
            ->tools([RuntimeStateGet::class])
            ->startFor(null);
        $session->recordMessage([
            'provider' => 'openai',
            'provider_event_id' => 'typed_1',
            'idempotency_key' => 'typed_1',
            'role' => 'user',
            'direction' => 'input',
            'modality' => 'text',
            'content' => 'Continue in writing.',
        ]);

        $this->postJson("/realtime-agent/sessions/{$session->id}/text", [
            'type' => 'message',
            'message' => 'Continue in writing.',
        ])->assertOk()
            ->assertJsonPath('response.id', 'resp_text_1')
            ->assertJsonPath('response._realtime_agent.message_id', 'msg_text_1')
            ->assertJsonPath('response._realtime_agent.persisted_server_side', true);

        Http::assertSent(static fn (Request $request): bool => $request->url() === 'https://api.openai.com/v1/responses'
            && data_get($request->data(), 'model') === 'gpt-5.6-terra'
            && data_get($request->data(), 'parallel_tool_calls') === false
            && data_get($request->data(), 'tools.0.name') === 'runtime_state_get'
            && data_get($request->data(), 'input.0.content.0.text') === 'Continue in writing.'
        );
        $audit = $session->audit()->jsonSerialize();
        self::assertSame(
            ['Continue in writing.', 'Here is the written continuation.'],
            array_map(static fn ($message): string => $message->content, $audit['messages']),
        );
        self::assertSame('0.00026000', $audit['totals']['estimated']['USD']);
    }

    public function test_openai_text_continuation_accepts_only_a_canonical_completed_tool_result(): void
    {
        config()->set('realtime-agent.providers.openai.api_key', 'test-key');
        Http::fake([
            '*/responses' => Http::response([
                'id' => 'resp_after_tool',
                'model' => 'gpt-5.6-terra',
                'output' => [[
                    'id' => 'msg_after_tool',
                    'type' => 'message',
                    'role' => 'assistant',
                    'content' => [['type' => 'output_text', 'text' => 'The state has been checked.']],
                ]],
            ]),
        ]);
        $session = $this->app->make(AgentSessionManager::class)
            ->make('provider.openai.text-tool')
            ->provider('openai')
            ->tools([RuntimeStateGet::class])
            ->startFor(null);
        $result = $session->execute(new ToolCall(
            id: 'call_text_1',
            name: 'runtime.state.get',
            arguments: [],
            baseRevision: $session->state()->revision(),
            idempotencyKey: 'call_text_1',
            providerCallId: 'call_text_1',
        ));

        $this->postJson("/realtime-agent/sessions/{$session->id}/text", [
            'type' => 'tool_result',
            'tool_result' => $result->toArray(),
        ])->assertOk()
            ->assertJsonPath('response.id', 'resp_after_tool');

        Http::assertSent(static fn (Request $request): bool => str_contains(
            (string) data_get($request->data(), 'input.0.content.0.text'),
            '"call_id":"call_text_1"',
        ));

        $this->postJson("/realtime-agent/sessions/{$session->id}/text", [
            'type' => 'tool_result',
            'tool_result' => [
                'call_id' => 'forged_call',
                'status' => 'completed',
                'output' => [],
                'state_revision' => $session->state()->revision(),
            ],
        ])->assertUnprocessable();
    }

    public function test_elevenlabs_tool_ids_are_cached_and_signed_url_is_faked(): void
    {
        config()->set('realtime-agent.providers.elevenlabs.api_key', 'test-key');
        config()->set('realtime-agent.providers.elevenlabs.agent_id', 'agent_test');
        Http::fake([
            '*/convai/tools' => Http::response(['id' => 'tool_provider_1']),
            '*/convai/conversation/get-signed-url*' => Http::response([
                'signed_url' => 'wss://example.test/signed',
                'conversation_id' => 'conversation_1',
            ]),
        ]);
        $session = $this->app->make(AgentSessionManager::class)
            ->make('provider.elevenlabs')
            ->provider('elevenlabs')
            ->tools([RuntimeStateGet::class])
            ->startFor(null);

        self::assertSame(1, ProviderToolRecord::query()->count());

        $this->postJson("/realtime-agent/sessions/{$session->id}/connect")
            ->assertOk()
            ->assertJsonPath('provider', 'elevenlabs')
            ->assertJsonPath('connection.signed_url', 'wss://example.test/signed')
            ->assertJsonPath('connection.tool_name_map.runtime_state_get', 'runtime.state.get');

        self::assertSame('conversation_1', $session->providerSessionId());
        self::assertSame(1, ProviderToolRecord::query()->count());
        Http::assertSentCount(2);
    }
}
