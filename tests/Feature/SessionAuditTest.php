<?php

declare(strict_types=1);

namespace AgentsFullDuplex\RealtimeAgent\Tests\Feature;

use AgentsFullDuplex\RealtimeAgent\Engine\AgentSessionManager;
use AgentsFullDuplex\RealtimeAgent\Engine\UsageCostCalculator;
use AgentsFullDuplex\RealtimeAgent\Events\AgentMessageRecorded;
use AgentsFullDuplex\RealtimeAgent\Events\AgentUsageRecorded;
use AgentsFullDuplex\RealtimeAgent\Tests\TestCase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;

final class SessionAuditTest extends TestCase
{
    public function test_transcript_usage_and_totals_are_exposed_as_an_authorized_canonical_audit(): void
    {
        Event::fake([AgentMessageRecorded::class, AgentUsageRecorded::class]);
        $session = $this->app->make(AgentSessionManager::class)
            ->make('audit.lesson')
            ->provider('fake')
            ->startFor(null);

        $message = [
            'provider' => 'fake',
            'provider_event_id' => 'message-1',
            'idempotency_key' => 'message-1',
            'role' => 'user',
            'direction' => 'input',
            'modality' => 'text',
            'status' => 'completed',
            'content' => 'Continue in text, please.',
            'metadata' => ['interaction_mode' => 'text'],
        ];

        $this->postJson("/realtime-agent/sessions/{$session->id}/messages", $message)
            ->assertOk()
            ->assertJsonPath('message.seq', 1);

        $this->postJson("/realtime-agent/sessions/{$session->id}/messages", [
            ...$message,
            'status' => 'corrected',
            'content' => 'Continue via text, please.',
        ])->assertOk()
            ->assertJsonPath('message.seq', 1)
            ->assertJsonPath('message.status', 'corrected');

        $this->postJson("/realtime-agent/sessions/{$session->id}/usage", [
            'provider' => 'fake',
            'provider_event_id' => 'usage-1',
            'idempotency_key' => 'usage-1',
            'kind' => 'response',
            'model' => 'fake-realtime',
            'units' => ['input_text_tokens' => 10, 'output_text_tokens' => 20],
            'raw' => ['deterministic' => true],
            'amount' => '999.00',
        ])->assertOk()
            ->assertJsonPath('usage.amount', '0.00000000')
            ->assertJsonPath('usage.status', 'final');

        $this->getJson("/realtime-agent/sessions/{$session->id}/audit")
            ->assertOk()
            ->assertJsonPath('audit.schema', 'realtime-agent-audit@1')
            ->assertJsonCount(1, 'audit.messages')
            ->assertJsonPath('audit.messages.0.content', 'Continue via text, please.')
            ->assertJsonCount(1, 'audit.usage')
            ->assertJsonPath('audit.totals.final.USD', '0.00000000')
            ->assertJsonPath('audit.totals.effective.USD', '0.00000000');

        Event::assertDispatched(AgentMessageRecorded::class, 2);
        Event::assertDispatched(AgentUsageRecorded::class);
    }

    public function test_browser_cannot_attribute_audit_data_to_another_provider(): void
    {
        $session = $this->app->make(AgentSessionManager::class)
            ->make('audit.provider-boundary')
            ->provider('fake')
            ->startFor(null);

        $this->postJson("/realtime-agent/sessions/{$session->id}/messages", [
            'provider' => 'openai',
            'idempotency_key' => 'forged',
            'role' => 'assistant',
            'direction' => 'output',
            'modality' => 'audio',
            'content' => 'Forged message.',
        ])->assertUnprocessable()
            ->assertJsonPath('error.code', 'invalid_audit_payload');
    }

    public function test_openai_cost_estimate_uses_modal_rates_and_cached_input_discount(): void
    {
        $estimate = $this->app->make(UsageCostCalculator::class)->estimate('openai', 'gpt-realtime', [
            'input_text_tokens' => 119,
            'cached_input_text_tokens' => 64,
            'input_audio_tokens' => 13,
            'cached_input_audio_tokens' => 0,
            'output_text_tokens' => 30,
            'output_audio_tokens' => 91,
        ]);

        self::assertSame('0.00696560', $estimate['amount']);
        self::assertSame('estimated', $estimate['status']);
        self::assertSame('gpt-realtime', $estimate['pricing']['model']);

        $unknown = $this->app->make(UsageCostCalculator::class)->estimate('openai', 'future-model', [
            'input_text_tokens' => 1,
        ]);
        self::assertNull($unknown['amount']);
        self::assertSame('unpriced', $unknown['status']);
    }

    public function test_openai_live_cost_estimate_separates_voice_duration_and_backend_tokens(): void
    {
        $calculator = $this->app->make(UsageCostCalculator::class);
        $voice = $calculator->estimate('openai', 'gpt-live-1', [
            'duration_seconds' => 90,
        ]);
        $backend = $calculator->estimate('openai', 'gpt-5.6-terra', [
            'input_text_tokens' => 1_000_000,
            'cached_input_text_tokens' => 250_000,
            'output_text_tokens' => 100_000,
        ]);

        self::assertSame('0.07500000', $voice['amount']);
        self::assertSame('gpt-live-1', $voice['pricing']['model']);
        self::assertSame('2.75000000', $backend['amount']);
        self::assertSame('gpt-5.6-terra', $backend['pricing']['model']);
    }

    public function test_openai_live_cumulative_duration_snapshots_replace_instead_of_sum(): void
    {
        $session = $this->app->make(AgentSessionManager::class)
            ->make('audit.openai-live')
            ->provider('openai')
            ->startFor(null);
        $usage = [
            'provider' => 'openai',
            'idempotency_key' => 'openai-live-duration:live_123',
            'kind' => 'duration',
            'model' => 'gpt-live-1',
            'raw' => ['cumulative_snapshot' => true],
        ];

        $this->postJson("/realtime-agent/sessions/{$session->id}/usage", [
            ...$usage,
            'provider_event_id' => 'usage_12',
            'units' => ['duration_seconds' => 12],
        ])->assertOk();
        $this->postJson("/realtime-agent/sessions/{$session->id}/usage", [
            ...$usage,
            'provider_event_id' => 'closed_19',
            'units' => ['duration_seconds' => 19],
            'raw' => ['cumulative_snapshot' => true, 'final' => true],
        ])->assertOk();

        $this->getJson("/realtime-agent/sessions/{$session->id}/audit")
            ->assertOk()
            ->assertJsonCount(1, 'audit.usage')
            ->assertJsonPath('audit.usage.0.provider_event_id', 'closed_19')
            ->assertJsonPath('audit.usage.0.units.duration_seconds', 19)
            ->assertJsonPath('audit.totals.estimated.USD', '0.01583333');
    }

    public function test_elevenlabs_post_call_reconciliation_imports_missing_turns_and_final_cost(): void
    {
        config()->set('realtime-agent.providers.elevenlabs.api_key', 'test-key');
        config()->set('realtime-agent.providers.elevenlabs.base_url', 'https://api.elevenlabs.test/v1');
        Http::fake([
            'https://api.elevenlabs.test/v1/convai/conversations/conv_audit' => Http::response([
                'status' => 'done',
                'metadata' => ['call_duration_secs' => 12, 'cost_fiat' => 1.1],
                'transcript' => [
                    ['role' => 'user', 'time_in_call_secs' => 1, 'message' => 'Hello'],
                    ['role' => 'agent', 'time_in_call_secs' => 2, 'message' => 'Hi there'],
                ],
            ]),
        ]);
        $session = $this->app->make(AgentSessionManager::class)
            ->make('audit.elevenlabs')
            ->provider('elevenlabs')
            ->startFor(null);

        $session->setProviderSessionId('conv_audit');

        $this->postJson("/realtime-agent/sessions/{$session->id}/audit/reconcile")
            ->assertOk()
            ->assertJsonCount(2, 'audit.messages')
            ->assertJsonPath('audit.usage.0.status', 'final')
            ->assertJsonPath('audit.usage.0.amount', '1.10000000')
            ->assertJsonPath('audit.totals.final.USD', '1.10000000')
            ->assertJsonPath('audit.totals.effective.USD', '1.10000000');

        Http::assertSentCount(1);
    }
}
