<?php

declare(strict_types=1);

use AgentsFullDuplex\RealtimeAgent\Providers\ElevenLabs\ElevenLabsProvider;
use AgentsFullDuplex\RealtimeAgent\Providers\Fake\FakeRealtimeProvider;
use AgentsFullDuplex\RealtimeAgent\Providers\OpenAI\OpenAIRealtimeProvider;

return [
    'default' => env('REALTIME_AGENT_PROVIDER', 'fake'),

    'providers' => [
        'fake' => [
            'driver' => FakeRealtimeProvider::class,
        ],
        'openai' => [
            'driver' => OpenAIRealtimeProvider::class,
            'api_key' => env('OPENAI_API_KEY'),
            'model' => env('OPENAI_REALTIME_MODEL', 'gpt-realtime'),
            'voice' => env('OPENAI_REALTIME_VOICE', 'marin'),
            'base_url' => env('OPENAI_BASE_URL', 'https://api.openai.com/v1'),
            'timeout' => 30,
        ],
        'elevenlabs' => [
            'driver' => ElevenLabsProvider::class,
            'api_key' => env('ELEVENLABS_API_KEY'),
            'agent_id' => env('ELEVENLABS_AGENT_ID'),
            'base_url' => env('ELEVENLABS_BASE_URL', 'https://api.elevenlabs.io/v1'),
            'timeout' => 30,
        ],
    ],

    'routes' => [
        'enabled' => true,
        'prefix' => 'realtime-agent',
        'middleware' => ['web', 'auth'],
    ],

    'state' => [
        'driver' => env('REALTIME_AGENT_STATE_DRIVER', 'database'),
        'persist_events' => true,
        'snapshot_debounce_ms' => 150,
    ],

    'security' => [
        'require_authorization' => true,
        'ui_command_ttl_seconds' => 15,
        'redact_tool_arguments' => true,
        'max_payload_kb' => 256,
        'authorization_ability' => null,
        'max_tool_calls_per_minute' => 120,
    ],
];
