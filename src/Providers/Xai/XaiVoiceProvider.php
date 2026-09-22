<?php

declare(strict_types=1);

namespace AgentsFullDuplex\RealtimeAgent\Providers\Xai;

use AgentsFullDuplex\RealtimeAgent\Contracts\RealtimeProviderContract;
use AgentsFullDuplex\RealtimeAgent\Data\AgentDefinition;
use AgentsFullDuplex\RealtimeAgent\Data\AgentSession;
use AgentsFullDuplex\RealtimeAgent\Data\ClientConnectionDescriptor;
use AgentsFullDuplex\RealtimeAgent\Data\ProviderCapabilities;
use AgentsFullDuplex\RealtimeAgent\Data\ProviderSession;
use AgentsFullDuplex\RealtimeAgent\Data\ProviderToolSet;
use AgentsFullDuplex\RealtimeAgent\Engine\ToolRegistry;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Http\Client\Factory as HttpFactory;

final class XaiVoiceProvider implements RealtimeProviderContract
{
    /** @var array<string, ProviderToolSet> */
    private array $toolSets = [];

    public function __construct(
        private readonly Config $config,
        private readonly HttpFactory $http,
        private readonly XaiToolMapper $mapper,
        private readonly ToolRegistry $tools,
    ) {}

    public function capabilities(): ProviderCapabilities
    {
        return new ProviderCapabilities(true, false, true, true, true, true, false);
    }

    public function prepare(AgentSession $session, AgentDefinition $definition): ProviderSession
    {
        return new ProviderSession(null, [
            'transport' => 'websocket',
            'api_variant' => 'xai-voice',
            'model' => $this->providerConfig('model'),
        ]);
    }

    public function clientConnection(AgentSession $session): ClientConnectionDescriptor
    {
        return new ClientConnectionDescriptor(
            sessionId: $session->id,
            provider: 'xai',
            connection: [
                'transport' => 'websocket',
                'api_variant' => 'xai-voice',
                'bootstrap_url' => $this->route($session->id),
            ],
            state: $session->state(),
        );
    }

    public function connect(AgentSession $session, ?string $offer = null): ClientConnectionDescriptor
    {
        $toolSet = $this->toolSets[$session->id]
            ?? $this->materializeTools($session, $this->tools->forDefinition($session->definition));
        $response = $this->http
            ->withToken($this->requiredConfig('api_key'))
            ->acceptJson()
            ->asJson()
            ->timeout((int) $this->providerConfig('timeout', 30))
            ->post(rtrim((string) $this->providerConfig('base_url'), '/').'/realtime/client_secrets', [
                'expires_after' => ['seconds' => (int) $this->providerConfig('client_secret_ttl_seconds', 300)],
            ])
            ->throw();
        $secret = (string) $response->json('value');

        if ($secret === '') {
            throw new \RuntimeException('xAI Voice token provisioning returned no client secret.');
        }

        return new ClientConnectionDescriptor(
            sessionId: $session->id,
            provider: 'xai',
            connection: [
                'transport' => 'websocket',
                'api_variant' => 'xai-voice',
                'endpoint' => 'wss://api.x.ai/v1/realtime',
                'client_secret' => $secret,
                'client_secret_expires_at' => $response->json('expires_at'),
                'model' => $this->requiredConfig('model'),
                'session' => [
                    'voice' => (string) $this->providerConfig('voice', 'eve'),
                    'instructions' => $session->definition->instructions
                        ."\n\nYou are the voice layer of a Laravel-controlled agent. Use only declared custom functions for application actions. Never claim an action succeeded until its function result says so.",
                    'reasoning' => ['effort' => (string) $this->providerConfig('reasoning_effort', 'none')],
                    'turn_detection' => ['type' => (string) $this->providerConfig('vad', 'server_vad')],
                    'input_audio_format' => 'pcm16',
                    'output_audio_format' => 'pcm16',
                    'input_audio_transcription' => ['model' => 'grok-transcribe'],
                    'tools' => array_map(static function (array $tool): array {
                        unset($tool['canonical_name']);

                        return $tool;
                    }, $toolSet->tools),
                    'tool_choice' => 'auto',
                ],
                'tool_name_map' => array_column($toolSet->tools, 'canonical_name', 'name'),
                'history' => $this->history($session),
                'context_max_characters' => (int) $this->providerConfig('context_max_characters', 1_600),
            ],
            state: $session->state(),
        );
    }

    public function materializeTools(AgentSession $session, iterable $tools): ProviderToolSet
    {
        $mapped = [];

        foreach ($tools as $tool) {
            $mapped[] = $this->mapper->map($tool);
        }

        return $this->toolSets[$session->id] = new ProviderToolSet($mapped);
    }

    /** @return list<array{role: string, text: string}> */
    private function history(AgentSession $session): array
    {
        $records = array_slice($session->audit()->messages, -(int) $this->providerConfig('history_max_messages', 64));
        $remaining = (int) $this->providerConfig('history_max_characters', 24_000);
        $history = [];

        foreach ($records as $record) {
            if (! in_array($record->role, ['user', 'assistant'], true) || $remaining <= 0) {
                continue;
            }

            $text = mb_substr($record->content, 0, $remaining);
            $remaining -= mb_strlen($text);
            $history[] = ['role' => $record->role, 'text' => $text];
        }

        return $history;
    }

    private function providerConfig(string $key, mixed $default = null): mixed
    {
        return $this->config->get("realtime-agent.providers.xai.{$key}", $default);
    }

    private function requiredConfig(string $key): string
    {
        $value = $this->providerConfig($key);

        if (! is_string($value) || $value === '') {
            throw new \LogicException("xAI provider configuration [{$key}] is required.");
        }

        return $value;
    }

    private function route(string $sessionId): string
    {
        return '/'.trim((string) $this->config->get('realtime-agent.routes.prefix', 'realtime-agent'), '/')
            ."/sessions/{$sessionId}/connect";
    }
}
