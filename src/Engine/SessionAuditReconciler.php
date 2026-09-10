<?php

declare(strict_types=1);

namespace AgentsFullDuplex\RealtimeAgent\Engine;

use AgentsFullDuplex\RealtimeAgent\Data\AgentSession;
use AgentsFullDuplex\RealtimeAgent\Data\SessionAudit;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Http\Client\Factory as HttpFactory;

final readonly class SessionAuditReconciler
{
    public function __construct(
        private Config $config,
        private HttpFactory $http,
        private SessionAuditManager $audits,
    ) {}

    public function reconcile(AgentSession $session): SessionAudit
    {
        if ($session->definition->provider !== 'elevenlabs') {
            return $this->audits->forSession($session);
        }

        $conversationId = $session->providerSessionId();

        if ($conversationId === null) {
            throw new \LogicException('The ElevenLabs conversation ID has not been recorded yet.');
        }

        if (preg_match('/^[A-Za-z0-9_:-]+$/', $conversationId) !== 1) {
            throw new \LogicException('The ElevenLabs conversation ID is invalid.');
        }

        $apiKey = (string) $this->config->get('realtime-agent.providers.elevenlabs.api_key', '');

        if ($apiKey === '') {
            throw new \LogicException('ElevenLabs provider configuration [api_key] is required for reconciliation.');
        }

        $baseUrl = rtrim((string) $this->config->get('realtime-agent.providers.elevenlabs.base_url'), '/');
        $payload = (array) $this->http
            ->withHeaders(['xi-api-key' => $apiKey])
            ->timeout((int) $this->config->get('realtime-agent.providers.elevenlabs.timeout', 30))
            ->get("{$baseUrl}/convai/conversations/{$conversationId}")
            ->throw()
            ->json();

        $this->mergeTranscript($session, (array) ($payload['transcript'] ?? []));
        $amount = $payload['metadata']['cost_fiat'] ?? null;
        $status = (string) ($payload['status'] ?? '');

        if (is_numeric($amount) && in_array($status, ['done', 'failed'], true)) {
            $this->audits->recordProviderCost(
                session: $session,
                providerEventId: "elevenlabs-conversation:{$conversationId}",
                amount: (string) $amount,
                currency: (string) $this->config->get('realtime-agent.providers.elevenlabs.cost_currency', 'USD'),
                raw: [
                    'conversation_id' => $conversationId,
                    'status' => $status,
                    'metadata' => $payload['metadata'] ?? [],
                ],
            );
        }

        return $this->audits->forSession($session);
    }

    /** @param list<array<string, mixed>> $transcript */
    private function mergeTranscript(AgentSession $session, array $transcript): void
    {
        $existing = [];

        foreach ($this->audits->forSession($session)->messages as $message) {
            $key = $message->role."\0".$message->content;
            $existing[$key] = ($existing[$key] ?? 0) + 1;
        }

        foreach ($transcript as $index => $item) {
            $role = match ((string) ($item['role'] ?? '')) {
                'agent', 'assistant' => 'assistant',
                'user' => 'user',
                default => null,
            };
            $content = trim((string) ($item['message'] ?? ''));

            if ($role === null || $content === '') {
                continue;
            }

            $key = $role."\0".$content;

            if (($existing[$key] ?? 0) > 0) {
                $existing[$key]--;

                continue;
            }

            $providerEventId = isset($item['id'])
                ? (string) $item['id']
                : 'reconciled-'.$index.'-'.substr(hash('sha256', $key), 0, 16);
            $this->audits->recordMessage($session, [
                'provider' => 'elevenlabs',
                'provider_event_id' => $providerEventId,
                'idempotency_key' => "elevenlabs-transcript:{$providerEventId}",
                'role' => $role,
                'direction' => $role === 'user' ? 'input' : 'output',
                'modality' => 'audio',
                'status' => 'completed',
                'content' => $content,
                'metadata' => [
                    'reconciled' => true,
                    'time_in_call_secs' => $item['time_in_call_secs'] ?? null,
                ],
            ]);
        }
    }
}
