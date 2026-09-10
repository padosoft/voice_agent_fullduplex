<?php

declare(strict_types=1);

namespace AgentsFullDuplex\RealtimeAgent\Providers\ElevenLabs;

use AgentsFullDuplex\RealtimeAgent\Data\ToolDefinition;
use AgentsFullDuplex\RealtimeAgent\Models\ProviderToolRecord;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Str;

final readonly class ElevenLabsToolRegistry
{
    public function __construct(
        private Config $config,
        private HttpFactory $http,
        private ElevenLabsToolMapper $mapper,
    ) {}

    /** @return array{id: string, name: string, canonical_name: string} */
    public function materialize(ToolDefinition $tool): array
    {
        $hash = $this->mapper->schemaHash($tool);
        $cached = ProviderToolRecord::query()
            ->where('provider', 'elevenlabs')
            ->where('schema_hash', $hash)
            ->first();

        if ($cached !== null) {
            return [
                'id' => $cached->provider_tool_id,
                'name' => $this->providerName($tool->name()),
                'canonical_name' => $tool->name(),
            ];
        }

        $apiKey = (string) $this->config->get('realtime-agent.providers.elevenlabs.api_key');

        if ($apiKey === '') {
            throw new \LogicException('ElevenLabs provider configuration [api_key] is required.');
        }

        $definition = [
            'type' => 'client',
            'name' => $this->providerName($tool->name()),
            'description' => $tool->descriptionText(),
            'expects_response' => true,
            'parameters' => $tool->schema(),
        ];
        $baseUrl = rtrim((string) $this->config->get(
            'realtime-agent.providers.elevenlabs.base_url',
            'https://api.elevenlabs.io/v1',
        ), '/');
        $response = $this->http
            ->withHeaders(['xi-api-key' => $apiKey])
            ->timeout((int) $this->config->get('realtime-agent.providers.elevenlabs.timeout', 30))
            ->post($baseUrl.'/convai/tools', ['tool_config' => $definition])
            ->throw();
        $providerId = (string) $response->json('id');

        if ($providerId === '') {
            throw new \RuntimeException('ElevenLabs did not return a provider tool ID.');
        }

        ProviderToolRecord::query()->create([
            'id' => (string) Str::ulid(),
            'provider' => 'elevenlabs',
            'schema_hash' => $hash,
            'provider_tool_id' => $providerId,
            'definition' => $definition,
            'synced_at' => now(),
        ]);

        return ['id' => $providerId, 'name' => $definition['name'], 'canonical_name' => $tool->name()];
    }

    public function providerName(string $canonicalName): string
    {
        return str_replace('.', '_', $canonicalName);
    }
}
