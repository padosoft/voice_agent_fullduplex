<?php

declare(strict_types=1);

use AgentsFullDuplex\RealtimeAgent\Providers\ElevenLabs\ElevenLabsProvider;
use AgentsFullDuplex\RealtimeAgent\Providers\Fake\FakeRealtimeProvider;
use AgentsFullDuplex\RealtimeAgent\Providers\Gemini\GeminiLiveProvider;
use AgentsFullDuplex\RealtimeAgent\Providers\OpenAI\OpenAIRealtimeProvider;
use AgentsFullDuplex\RealtimeAgent\Providers\Xai\XaiVoiceProvider;

return [
    'default' => env('REALTIME_AGENT_PROVIDER', 'fake'),

    'providers' => [
        'fake' => [
            'driver' => FakeRealtimeProvider::class,
        ],
        'openai' => [
            'driver' => OpenAIRealtimeProvider::class,
            'api_key' => env('OPENAI_API_KEY'),
            'model' => env('OPENAI_LIVE_MODEL', 'gpt-live-1'),
            'backend_model' => env('OPENAI_LIVE_BACKEND_MODEL', 'gpt-5.6-terra'),
            'voice' => env('OPENAI_LIVE_VOICE', 'marin'),
            'store' => env('OPENAI_LIVE_STORE', false),
            'history_max_messages' => 64,
            'history_max_characters' => 24_000,
            'context_max_characters' => 1_600,
            'transcript_gap_ms' => 1_200,
            'close_timeout_ms' => 3_000,
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
        'gemini' => [
            'driver' => GeminiLiveProvider::class,
            'api_key' => env('GEMINI_API_KEY'),
            'model' => env('GEMINI_LIVE_MODEL', 'gemini-3.8-live'),
            'voice' => env('GEMINI_LIVE_VOICE', 'Puck'),
            'base_url' => env('GEMINI_BASE_URL', 'https://generativelanguage.googleapis.com/v1beta'),
            'token_ttl_seconds' => (int) env('GEMINI_LIVE_TOKEN_TTL_SECONDS', 1_800),
            'new_session_ttl_seconds' => (int) env('GEMINI_LIVE_NEW_SESSION_TTL_SECONDS', 60),
            'history_max_messages' => (int) env('GEMINI_LIVE_HISTORY_MAX_MESSAGES', 64),
            'history_max_characters' => (int) env('GEMINI_LIVE_HISTORY_MAX_CHARACTERS', 24_000),
            'context_max_characters' => (int) env('GEMINI_LIVE_CONTEXT_MAX_CHARACTERS', 1_600),
            'context_compression_trigger_tokens' => (int) env('GEMINI_LIVE_CONTEXT_COMPRESSION_TRIGGER_TOKENS', 25_000),
            'context_compression_sliding_window_tokens' => (int) env('GEMINI_LIVE_CONTEXT_COMPRESSION_WINDOW_TOKENS', 8_000),
            'timeout' => 30,
        ],
        'xai' => [
            'driver' => XaiVoiceProvider::class,
            'api_key' => env('XAI_API_KEY'),
            'model' => env('XAI_VOICE_MODEL', 'grok-voice-latest'),
            'voice' => env('XAI_VOICE', 'eve'),
            'reasoning_effort' => env('XAI_VOICE_REASONING_EFFORT', 'none'),
            'vad' => env('XAI_VOICE_VAD', 'server_vad'),
            'client_secret_ttl_seconds' => (int) env('XAI_VOICE_CLIENT_SECRET_TTL_SECONDS', 300),
            'history_max_messages' => (int) env('XAI_VOICE_HISTORY_MAX_MESSAGES', 64),
            'history_max_characters' => (int) env('XAI_VOICE_HISTORY_MAX_CHARACTERS', 24_000),
            'context_max_characters' => (int) env('XAI_VOICE_CONTEXT_MAX_CHARACTERS', 1_600),
            'base_url' => env('XAI_BASE_URL', 'https://api.x.ai/v1'),
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
                'version' => 'openai-2026-09-11',
                'default_model' => 'gpt-live-1',
                'aliases' => [
                    'gpt-realtime-2025-08-28' => 'gpt-realtime',
                ],
                'models' => [
                    'gpt-live-1' => [
                        'currency' => 'USD',
                        'unit_size' => 60,
                        'rates' => [
                            'duration_seconds' => 0.05,
                        ],
                        'effective_at' => '2026-09-10',
                        'source' => 'https://developers.openai.com/api/docs/models/gpt-live-1',
                    ],
                    'gpt-5.6-terra' => [
                        'currency' => 'USD',
                        'unit_size' => 1_000_000,
                        'rates' => [
                            'input_text_tokens' => 2.00,
                            'cached_input_text_tokens' => 0.20,
                            'output_text_tokens' => 12.00,
                        ],
                        'effective_at' => '2026-09-11',
                        'source' => 'https://developers.openai.com/api/docs/models/gpt-5.6-terra',
                    ],
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
            'gemini' => [
                'version' => 'gemini-2026-09-22',
                'default_model' => 'gemini-3.8-live',
                'models' => [
                    'gemini-3.8-live' => [
                        'currency' => 'USD',
                        'unit_size' => 1_000_000,
                        'rates' => [
                            'input_text_tokens' => 0.75,
                            'input_audio_tokens' => 3.00,
                            'output_text_tokens' => 4.50,
                            'output_audio_tokens' => 12.00,
                        ],
                        'effective_at' => '2026-09-22',
                        'source' => 'https://ai.google.dev/gemini-api/docs/pricing',
                    ],
                ],
            ],
            'xai' => [
                'version' => 'xai-2026-09-22',
                'default_model' => 'grok-voice-latest',
                'aliases' => [
                    'grok-voice-think-fast-2.0' => 'grok-voice-latest',
                ],
                'models' => [
                    'grok-voice-latest' => [
                        'currency' => 'USD',
                        'unit_size' => 1,
                        'unit_sizes' => [
                            'input_audio_seconds' => 60,
                            'output_audio_seconds' => 60,
                            'text_input_messages' => 1,
                        ],
                        'rates' => [
                            'input_audio_seconds' => 0.08,
                            'output_audio_seconds' => 0.08,
                            'text_input_messages' => 0.004,
                        ],
                        'effective_at' => '2026-09-22',
                        'source' => 'https://docs.x.ai/developers/models/speech-to-speech',
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
