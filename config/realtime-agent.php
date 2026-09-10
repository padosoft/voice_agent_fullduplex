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
            'model' => env('OPENAI_REALTIME_MODEL'),
            'voice' => env('OPENAI_REALTIME_VOICE'),
        ],
        'elevenlabs' => [
            'driver' => ElevenLabsProvider::class,
            'api_key' => env('ELEVENLABS_API_KEY'),
            'agent_id' => env('ELEVENLABS_AGENT_ID'),
        ],
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
    ],
];
