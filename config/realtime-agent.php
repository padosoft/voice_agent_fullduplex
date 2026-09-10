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
            'transcription_model' => env('OPENAI_REALTIME_TRANSCRIPTION_MODEL', 'gpt-4o-mini-transcribe'),
            'base_url' => env('OPENAI_BASE_URL', 'https://api.openai.com/v1'),
            'timeout' => 30,
        ],
        'elevenlabs' => [
            'driver' => ElevenLabsProvider::class,
            'api_key' => env('ELEVENLABS_API_KEY'),
            'agent_id' => env('ELEVENLABS_AGENT_ID'),
            'base_url' => env('ELEVENLABS_BASE_URL', 'https://api.elevenlabs.io/v1'),
            'cost_currency' => env('ELEVENLABS_COST_CURRENCY', 'USD'),
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

    'audit' => [
        'max_message_characters' => 65_535,
        'pricing' => [
            'fake' => [
                'version' => 'fake-v1',
                'default_model' => 'fake-realtime',
                'models' => [
                    'fake-realtime' => [
                        'currency' => 'USD',
                        'unit_size' => 1_000_000,
                        'rates' => [
                            'input_text_tokens' => 0,
                            'output_text_tokens' => 0,
                            'input_audio_tokens' => 0,
                            'output_audio_tokens' => 0,
                        ],
                        'source' => 'deterministic-test-provider',
                    ],
                ],
            ],
            'openai' => [
                'version' => 'openai-2026-09-10',
                'default_model' => 'gpt-realtime',
                'aliases' => [
                    'gpt-realtime-2025-08-28' => 'gpt-realtime',
                ],
                'models' => [
                    'gpt-realtime' => [
                        'currency' => 'USD',
                        'unit_size' => 1_000_000,
                        'rates' => [
                            'input_text_tokens' => 4.00,
                            'cached_input_text_tokens' => 0.40,
                            'output_text_tokens' => 16.00,
                            'input_audio_tokens' => 32.00,
                            'cached_input_audio_tokens' => 0.40,
                            'output_audio_tokens' => 64.00,
                            'input_image_tokens' => 5.00,
                            'cached_input_image_tokens' => 0.50,
                        ],
                        'source' => 'https://developers.openai.com/api/docs/models/gpt-realtime',
                    ],
                    'gpt-4o-mini-transcribe' => [
                        'currency' => 'USD',
                        'unit_size' => 1_000_000,
                        'rates' => [
                            'input_audio_tokens' => 1.25,
                            'output_text_tokens' => 5.00,
                        ],
                        'source' => 'https://developers.openai.com/api/docs/models/gpt-4o-mini-transcribe',
                    ],
                ],
            ],
        ],
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
