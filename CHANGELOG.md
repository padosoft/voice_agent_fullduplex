# Changelog

All notable changes to this project will be documented in this file.

The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the project uses semantic versioning.

## [Unreleased]

## [0.2.0] - 2026-09-22

### Added

- Gemini Live support through the Gemini Developer API, using Laravel-provisioned constrained ephemeral tokens, direct browser WebSockets, 16 kHz PCM input, interruption-aware 24 kHz playback, session resumption, final input/output transcripts, blocking Laravel-brokered functions, and modality-token cost records.
- xAI Grok Voice support through short-lived browser client secrets, direct Speech-to-Speech WebSockets, server-controlled VAD and custom functions, bounded canonical history on reconnect, transcript correction handling, and estimated input/output PCM-duration plus text-message cost records.
- Versioned Gemini and xAI rate-card snapshots, provider-specific doctor checks, contract coverage for credential provisioning/tool mapping/cost idempotency, unit browser-driver tests, and a provider-compatible Playwright flow that validates transcripts, tools, usage, and completed state.

### Security

- Permanent Gemini and xAI credentials remain server-side. Ephemeral browser credentials are neither appended to Laravel events nor retained in connection audit data.
- Gemini native search/MCP and xAI provider-native tools are disabled; only declared custom functions can cross the Laravel Tool Broker serially.

## [0.1.0] - 2026-09-14

### Added

- A Laravel 12–13 package with automatic discovery, configurable authenticated control routes, timestamp-safe migration publishing, install and doctor commands, and a database-backed state store.
- Immutable, versioned contracts for sessions, state, events, goals, tools, confirmations, semantic Surfaces, signed UI commands, ordered messages, usage, and cost records.
- Transactional state mutations with optimistic revisions, append-only events, idempotent tool execution, finish policies, confirmation workflows, and durable provider tool identifiers.
- A provider-neutral TypeScript client with semantic Surface registration, DOM scanning, diff/debounce synchronization, signed UI-command execution, reconnection, and voice/text modality switching.
- A deterministic, zero-cost Fake Provider for development and automated testing.
- OpenAI GPT-Live WebRTC support with server-side credential handling, Responses delegation, nested tool calls, fragment-preserving transcripts, graceful voice shutdown, canonical history rehydration, and text continuation on the same Laravel session.
- ElevenLabs signed WebSocket support with contextual Surface updates, client tool brokerage, durable schema-mapped tool IDs, transcript import, and authenticated post-call cost reconciliation.
- Provider-neutral audit APIs and Laravel events for transcripts, tool lifecycle, estimated usage, provider-final costs, accounting ledgers, observability systems, and data warehouses.
- PHPUnit/Testbench, Larastan, Pint, Vitest/jsdom, schema synchronization, README validation, clean package-archive checks, and Playwright coverage, including the complete three-goal Fake Provider flow.
- Installable Codex skills for downstream integration and verified local releases, with a copy-ready Handoff and anonymous reusable integration patterns.

### Security

- Session ownership and optional Laravel Gate authorization are enforced independently of route middleware.
- Provider input, tool arguments, Surface snapshots, revisions, payload sizes, idempotency keys, confirmations, and UI-command expiry/signatures are validated by Laravel.
- Provider credentials stay server-side; arbitrary selectors, HTML, scripts, unregistered UI actions, and browser-supplied monetary amounts are rejected.
- Sensitive tool arguments are redacted from durable audit records by default.

[Unreleased]: https://github.com/padosoft/voice_agent_fullduplex/compare/v0.2.0...HEAD
[0.2.0]: https://github.com/padosoft/voice_agent_fullduplex/compare/v0.1.0...v0.2.0
[0.1.0]: https://github.com/padosoft/voice_agent_fullduplex/releases/tag/v0.1.0
