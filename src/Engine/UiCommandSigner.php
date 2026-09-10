<?php

declare(strict_types=1);

namespace AgentsFullDuplex\RealtimeAgent\Engine;

use AgentsFullDuplex\RealtimeAgent\Data\UiCommand;
use Illuminate\Contracts\Config\Repository as Config;
use RuntimeException;

final readonly class UiCommandSigner
{
    public function __construct(private Config $config) {}

    public function sign(UiCommand $command): string
    {
        return hash_hmac('sha256', $this->payload($command), $this->key());
    }

    public function verify(UiCommand $command, string $token): bool
    {
        return hash_equals($this->sign($command), $token);
    }

    private function payload(UiCommand $command): string
    {
        $encoded = json_encode($command->unsignedPayload(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

        return $encoded;
    }

    private function key(): string
    {
        $key = (string) $this->config->get('app.key');

        if (str_starts_with($key, 'base64:')) {
            $decoded = base64_decode(substr($key, 7), true);

            if ($decoded !== false) {
                $key = $decoded;
            }
        }

        if ($key === '') {
            throw new RuntimeException('APP_KEY is required to sign realtime UI commands.');
        }

        return $key;
    }
}
