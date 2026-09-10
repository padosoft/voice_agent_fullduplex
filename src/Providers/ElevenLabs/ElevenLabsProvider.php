<?php

declare(strict_types=1);

namespace AgentsFullDuplex\RealtimeAgent\Providers\ElevenLabs;

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

final class ElevenLabsProvider implements RealtimeProviderContract
{
    /** @var array<string, ProviderToolSet> */
    private array $toolSets = [];

    public function __construct(
        private readonly Config $config,
        private readonly HttpFactory $http,
        private readonly ElevenLabsToolRegistry $providerTools,
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
            'agent_id' => $this->providerConfig('agent_id'),
        ]);
    }

    public function clientConnection(AgentSession $session): ClientConnectionDescriptor
    {
        return new ClientConnectionDescriptor(
            sessionId: $session->id,
            provider: 'elevenlabs',
            connection: [
                'transport' => 'websocket',
                'bootstrap_url' => $this->route($session->id),
            ],
            state: $session->state(),
        );
    }

    public function connect(AgentSession $session, ?string $offer = null): ClientConnectionDescriptor
    {
        $apiKey = $this->requiredConfig('api_key');
        $agentId = $this->requiredConfig('agent_id');
        $toolSet = $this->toolSets[$session->id]
            ?? $this->materializeTools($session, $this->tools->forDefinition($session->definition));
        $response = $this->http
            ->withHeaders(['xi-api-key' => $apiKey])
            ->timeout((int) $this->providerConfig('timeout', 30))
            ->get(rtrim((string) $this->providerConfig('base_url'), '/').'/convai/conversation/get-signed-url', [
                'agent_id' => $agentId,
                'include_conversation_id' => 'true',
            ])
            ->throw();

        return new ClientConnectionDescriptor(
            sessionId: $session->id,
            provider: 'elevenlabs',
            connection: [
                'transport' => 'websocket',
                'signed_url' => (string) $response->json('signed_url'),
                'conversation_id' => $response->json('conversation_id'),
                'tool_ids' => array_column($toolSet->tools, 'id'),
                'tool_name_map' => array_column($toolSet->tools, 'canonical_name', 'name'),
                'dynamic_variables' => ['realtime_agent_session_id' => $session->id],
                'overrides' => [
                    'agent' => [
                        'prompt' => [
                            'prompt' => $session->definition->instructions,
                            'tool_ids' => array_column($toolSet->tools, 'id'),
                        ],
                    ],
                ],
            ],
            state: $session->state(),
        );
    }

    public function materializeTools(AgentSession $session, iterable $tools): ProviderToolSet
    {
        $materialized = [];

        foreach ($tools as $tool) {
            $materialized[] = $this->providerTools->materialize($tool);
        }

        return $this->toolSets[$session->id] = new ProviderToolSet($materialized);
    }

    private function providerConfig(string $key, mixed $default = null): mixed
    {
        return $this->config->get("realtime-agent.providers.elevenlabs.{$key}", $default);
    }

    private function requiredConfig(string $key): string
    {
        $value = $this->providerConfig($key);

        if (! is_string($value) || $value === '') {
            throw new \LogicException("ElevenLabs provider configuration [{$key}] is required.");
        }

        return $value;
    }

    private function route(string $sessionId): string
    {
        return '/'.trim((string) $this->config->get('realtime-agent.routes.prefix', 'realtime-agent'), '/')
            ."/sessions/{$sessionId}/connect";
    }
}
