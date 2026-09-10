# Changelog

All notable changes to this project will be documented in this file.

The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the project uses semantic versioning.

## [Unreleased]

### Added

- Laravel 12–13 package discovery, configurable authenticated control routes, install and doctor commands.
- Versioned state, event, goal, tool, Surface, confirmation, and signed UI-command protocols.
- Transactional database state, append-only events, optimistic revisions, idempotent tool audit, and durable ElevenLabs tool IDs.
- Framework-neutral TypeScript client with DOM Surface adapter, semantic diffing, UI command execution, and reconnection support.
- Deterministic Fake Provider plus OpenAI WebRTC and ElevenLabs WebSocket adapters.
- PHPUnit, Vitest, and Playwright coverage with provider HTTP fakes.
- Ordered, provider-neutral conversation transcripts with voice-to-text continuation on the same session.
- Versioned usage and cost records, OpenAI token accounting, ElevenLabs post-call reconciliation, and authorized audit exports.
- Laravel audit events for external ledgers, observability pipelines, and transcript archives.
