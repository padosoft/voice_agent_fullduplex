<?php

declare(strict_types=1);

namespace AgentsFullDuplex\RealtimeAgent\Console;

use Illuminate\Console\Command;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Database\DatabaseManager;
use Throwable;

final class DoctorCommand extends Command
{
    protected $signature = 'realtime-agent:doctor';

    protected $description = 'Check the local Realtime Agent configuration without contacting providers.';

    public function handle(Config $config, DatabaseManager $database): int
    {
        $provider = (string) $config->get('realtime-agent.default', 'fake');
        $checks = [
            'Default provider' => $provider,
            'Control routes' => $config->get('realtime-agent.routes.enabled', true) ? 'enabled' : 'disabled',
            'State driver' => (string) $config->get('realtime-agent.state.driver', 'database'),
        ];
        $failed = false;

        if ($provider === 'openai') {
            $checks['OpenAI credentials'] = $this->configured($config, 'openai', ['api_key', 'model']);
        } elseif ($provider === 'elevenlabs') {
            $checks['ElevenLabs credentials'] = $this->configured($config, 'elevenlabs', ['api_key', 'agent_id']);
        } else {
            $checks['Fake provider'] = 'ready (no credentials required)';
        }

        if ($config->get('realtime-agent.state.driver') === 'database') {
            try {
                $schema = $database->connection()->getSchemaBuilder();
                $tables = [
                    'realtime_agent_sessions',
                    'realtime_agent_events',
                    'realtime_agent_tool_calls',
                    'realtime_agent_provider_tools',
                ];
                $missing = array_values(array_filter($tables, static fn (string $table): bool => ! $schema->hasTable($table)));
                $checks['Database schema'] = $missing === [] ? 'ready' : 'missing: '.implode(', ', $missing);
                $failed = $missing !== [];
            } catch (Throwable) {
                $checks['Database schema'] = 'unavailable';
                $failed = true;
            }
        }

        foreach ($checks as $label => $value) {
            $this->components->twoColumnDetail($label, (string) $value);
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }

    /** @param list<string> $keys */
    private function configured(Config $config, string $provider, array $keys): string
    {
        foreach ($keys as $key) {
            $value = $config->get("realtime-agent.providers.{$provider}.{$key}");

            if (! is_string($value) || $value === '') {
                return "missing {$key}";
            }
        }

        return 'configured';
    }
}
