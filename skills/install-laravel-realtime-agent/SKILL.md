---
name: install-laravel-realtime-agent
description: Install and integrate agents-full-duplex/laravel-realtime-agent into a Laravel 12 or 13 application, including provider selection, browser runtime, semantic Surfaces, authorization, transcripts, cost audit, and verification. Use when adding or updating this package in an authenticated Laravel product.
---

# Install Laravel Realtime Agent

Integrate the package into an existing Laravel application without weakening the target application's authentication, authorization, data boundaries, or frontend conventions.

## Workflow

1. Inspect the target repository before editing: Laravel/PHP/Node versions, authentication, models, routes, frontend entrypoints, Vite configuration, tests, dirty git state, and Herd URL.
2. Read [references/integration-manual.md](references/integration-manual.md) completely before changing the target application. Treat it as the package-specific source of truth.
   - When the target resembles an adaptive-learning platform, a connected-environment controller, or a revisioned creative workspace, also read only the matching anonymous section of [references/case-studies.md](references/case-studies.md). Treat every class and identifier there as illustrative.
3. Install the stable `0.2` line from the Composer registry by default. Use a local path only when the target must consume unreleased source changes. Never invent a path or credential; Marco's known local checkout is `/Users/marco/packages/agents-full-duplex-ui-bridge`.
4. Install and prove the integration with the Fake Provider first. It is deterministic, free, and requires no provider credentials.
5. Create sessions only in authenticated application PHP code with `RealtimeAgent::make(...)->startFor($user)`. Do not add a generic browser session-creation endpoint.
6. Expose only a semantic Surface made of registered IDs, state, and allowed actions. Never expose raw HTML, arbitrary selectors, scripts, secrets, or an unrestricted DOM snapshot.
7. Select the browser driver from the server-issued descriptor. Do not let a browser request override the provider stored in the Laravel session.
8. Keep state mutations and tools behind revision checks, validation, authorization, confirmation where required, and idempotency.
9. Preserve transcript, tool-call, and usage/cost auditing. If the application exports accounting or observability data, consume the package's Laravel audit events instead of coupling to a provider payload.
10. Run the target application's relevant tests plus `php artisan realtime-agent:doctor`. Report what was verified and clearly separate Fake Provider validation from opt-in live-provider testing.

If the integration also changes this package's source, run its complete upstream gate: `composer check`, `npm run check`, `npm run test:e2e`, and both bundled skill validators. `npm run check` includes the repository's README visual validator.

## Provider boundary

- `fake`: default for development and automated tests; no keys and no external calls.
- `openai`: GPT-Live voice over WebRTC, with the standard API key kept in Laravel. Text continuation uses the configured Responses backend while retaining the same canonical Laravel session.
- `elevenlabs`: signed WebSocket URL obtained from Laravel; reconcile the completed conversation to import provider-final cost and any missing turns.
- `gemini`: Gemini Live over direct WebSocket with a Laravel-provisioned, configuration-constrained ephemeral token. It records provider usage metadata and uses only blocking Laravel-mapped functions.
- `xai`: Grok Voice over direct WebSocket with a Laravel-provisioned client secret. It recreates the short-lived connection on reconnect and records estimated input/output PCM duration plus text-message usage.

For Gemini and xAI, a client descriptor is short-lived credential material: use it only to establish the authenticated session, do not log it, store it, or reuse it across users. Native provider search, MCP, and arbitrary tools stay disabled; every custom function must pass through Laravel's Tool Broker.

Do not perform a paid live smoke test unless the user explicitly asks and the corresponding credentials already exist in the target environment.

## Completion standard

An integration is complete only when an authenticated user can start a server-created session, the browser can connect with the matching driver, a declared Surface synchronizes, text works with the Fake Provider, audit data is readable by the owner, unauthorized access is rejected, and the target project builds successfully.
