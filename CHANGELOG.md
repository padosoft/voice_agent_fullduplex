# Changelog

All notable changes to this project will be documented in this file.

The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the project uses semantic versioning.

## [Unreleased]

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

[Unreleased]: https://github.com/padosoft/voice_agent_fullduplex/compare/v0.1.0...HEAD
[0.1.0]: https://github.com/padosoft/voice_agent_fullduplex/releases/tag/v0.1.0
