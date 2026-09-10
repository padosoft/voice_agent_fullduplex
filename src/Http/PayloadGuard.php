<?php

declare(strict_types=1);

namespace AgentsFullDuplex\RealtimeAgent\Http;

use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Validation\ValidationException;

final readonly class PayloadGuard
{
    public function __construct(private Config $config) {}

    public function enforceSize(string $payload): void
    {
        $limit = (int) $this->config->get('realtime-agent.security.max_payload_kb', 256) * 1024;

        if (strlen($payload) > $limit) {
            throw ValidationException::withMessages(['payload' => 'The realtime payload is too large.']);
        }
    }

    /** @param array<string, mixed> $snapshot */
    public function surface(array $snapshot): void
    {
        $surface = $snapshot['surface'] ?? null;

        if (! is_string($surface) || ! preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{0,127}$/', $surface)) {
            throw ValidationException::withMessages(['snapshot.surface' => 'The Surface identifier is invalid.']);
        }

        $components = $snapshot['components'] ?? [];

        if (! is_array($components)) {
            throw ValidationException::withMessages(['snapshot.components' => 'Surface components must be an object.']);
        }

        foreach ($components as $target => $component) {
            if (! is_string($target) || ! preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{0,127}$/', $target)) {
                throw ValidationException::withMessages(['snapshot.components' => 'A semantic target identifier is invalid.']);
            }

            if (! is_array($component)) {
                throw ValidationException::withMessages(["snapshot.components.{$target}" => 'A component must be an object.']);
            }

            foreach ((array) ($component['actions'] ?? []) as $action) {
                if (! is_string($action) || ! preg_match('/^[A-Za-z][A-Za-z0-9._:-]{0,63}$/', $action)) {
                    throw ValidationException::withMessages(["snapshot.components.{$target}.actions" => 'An action name is invalid.']);
                }
            }
        }

        $this->rejectExecutableKeys($snapshot);
    }

    /** @param array<string, mixed> $value */
    private function rejectExecutableKeys(array $value): void
    {
        foreach ($value as $key => $child) {
            if (is_string($key) && in_array(strtolower($key), ['selector', 'script', 'code', 'html', 'javascript'], true)) {
                throw ValidationException::withMessages(['snapshot' => "Surface field [{$key}] is not allowed."]);
            }

            if (is_array($child)) {
                $this->rejectExecutableKeys($child);
            }
        }
    }
}
