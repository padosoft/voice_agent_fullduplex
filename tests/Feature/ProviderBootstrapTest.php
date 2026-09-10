<?php

declare(strict_types=1);

namespace AgentsFullDuplex\RealtimeAgent\Tests\Feature;

use AgentsFullDuplex\RealtimeAgent\Contracts\EventStoreContract;
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

    public function test_openai_sdp_is_forwarded_by_laravel_with_fake_http(): void
    {
        config()->set('realtime-agent.providers.openai.api_key', 'test-key');
        config()->set('realtime-agent.providers.openai.model', 'gpt-realtime-test');
        Http::fake([
            '*/realtime/calls' => Http::response('v=0\r\no=answer', 200, ['Location' => '/v1/realtime/calls/call_1']),
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
            ->assertJsonPath('connection.answer_sdp', 'v=0\r\no=answer')
            ->assertJsonPath('connection.tool_name_map.runtime_state_get', 'runtime.state.get');

        Http::assertSent(static fn (Request $request): bool => $request->url() === 'https://api.openai.com/v1/realtime/calls'
            && $request->hasHeader('Authorization', 'Bearer test-key')
            && $request->hasHeader('OpenAI-Safety-Identifier')
        );
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

        self::assertSame(1, ProviderToolRecord::query()->count());
        Http::assertSentCount(2);
    }
}
