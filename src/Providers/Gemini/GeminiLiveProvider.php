<?php

declare(strict_types=1);

namespace AgentsFullDuplex\RealtimeAgent\Providers\Gemini;

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

final class GeminiLiveProvider implements RealtimeProviderContract
{
    /** @var array<string, ProviderToolSet> */
    private array $toolSets = [];

    public function __construct(
        private readonly Config $config,
        private readonly HttpFactory $http,
        private readonly GeminiToolMapper $mapper,
        private readonly ToolRegistry $tools,
    ) {}

    public function capabilities(): ProviderCapabilities
    {
        return new ProviderCapabilities(true, false, true, false, true, true, false);
    }

    public function prepare(AgentSession $session, AgentDefinition $definition): ProviderSession
    {
        return new ProviderSession(null, [
            'transport' => 'websocket',
            'api_variant' => 'gemini-live',
            'model' => $this->providerConfig('model'),
        ]);
    }

    public function clientConnection(AgentSession $session): ClientConnectionDescriptor
    {
        return new ClientConnectionDescriptor(
            sessionId: $session->id,
            provider: 'gemini',
            connection: [
                'transport' => 'websocket',
                'api_variant' => 'gemini-live',
                'bootstrap_url' => $this->route($session->id),
            ],
            state: $session->state(),
        );
    }

    public function connect(AgentSession $session, ?string $offer = null): ClientConnectionDescriptor
    {
        $toolSet = $this->toolSets[$session->id]
            ?? $this->materializeTools($session, $this->tools->forDefinition($session->definition));
        $now = now();
        $model = $this->requiredConfig('model');
        $configuration = [
            'responseModalities' => ['AUDIO'],
            'systemInstruction' => [
                'parts' => [[
                    'text' => $session->definition->instructions
                        ."\n\nYou are the voice layer of a Laravel-controlled agent. Use only the declared functions for application actions. Never claim an action succeeded until its function result says so.",
                ]],
            ],
            'generationConfig' => [
                'speechConfig' => [
                    'voiceConfig' => [
                        'prebuiltVoiceConfig' => ['voiceName' => (string) $this->providerConfig('voice', 'Puck')],
                    ],
                ],
            ],
            'inputAudioTranscription' => [],
            'outputAudioTranscription' => [],
            'sessionResumption' => [],
            'contextWindowCompression' => [
                'triggerTokens' => (int) $this->providerConfig('context_compression_trigger_tokens', 25_000),
                'slidingWindow' => ['targetTokens' => (int) $this->providerConfig('context_compression_sliding_window_tokens', 8_000)],
            ],
            'tools' => $toolSet->tools === [] ? [] : [[
                'functionDeclarations' => array_map(static function (array $tool): array {
                    unset($tool['canonical_name']);

                    return $tool;
                }, $toolSet->tools),
            ]],
        ];
        $response = $this->http
            ->withHeaders(['x-goog-api-key' => $this->requiredConfig('api_key')])
            ->acceptJson()
            ->asJson()
            ->timeout((int) $this->providerConfig('timeout', 30))
            ->post(rtrim((string) $this->providerConfig('base_url'), '/').'/auth_tokens', [
                'uses' => 1,
                'expireTime' => $now->copy()->addSeconds((int) $this->providerConfig('token_ttl_seconds', 1_800))->toISOString(),
                'newSessionExpireTime' => $now->copy()->addSeconds((int) $this->providerConfig('new_session_ttl_seconds', 60))->toISOString(),
                'liveConnectConstraints' => [
                    'model' => 'models/'.$model,
                    'config' => $configuration,
                ],
            ])
            ->throw();
        $token = (string) $response->json('name');

        if ($token === '') {
            throw new \RuntimeException('Gemini Live token provisioning returned no ephemeral token.');
        }

        return new ClientConnectionDescriptor(
            sessionId: $session->id,
            provider: 'gemini',
            connection: [
                'transport' => 'websocket',
                'api_variant' => 'gemini-live',
                'endpoint' => 'wss://generativelanguage.googleapis.com/ws/google.ai.generativelanguage.v1beta.GenerativeService.BidiGenerateContentConstrained',
                'access_token' => $token,
                'model' => $model,
                'setup' => ['model' => 'models/'.$model, ...$configuration],
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
            $history[] = ['role' => $record->role === 'assistant' ? 'model' : 'user', 'text' => $text];
        }

        return $history;
    }

    private function providerConfig(string $key, mixed $default = null): mixed
    {
        return $this->config->get("realtime-agent.providers.gemini.{$key}", $default);
    }

    private function requiredConfig(string $key): string
    {
        $value = $this->providerConfig($key);

        if (! is_string($value) || $value === '') {
            throw new \LogicException("Gemini provider configuration [{$key}] is required.");
        }

        return $value;
    }

    private function route(string $sessionId): string
    {
        return '/'.trim((string) $this->config->get('realtime-agent.routes.prefix', 'realtime-agent'), '/')
            ."/sessions/{$sessionId}/connect";
    }
}
