<p align="center">
  <img src="docs/readme/logo.svg" width="112" alt="Laravel Realtime Agent logo">
</p>

<h1 align="center">Laravel Realtime Agent</h1>

<p align="center">
  Provider-agnostic realtime agents with Laravel-authoritative state, goals, tools, and semantic UI control.
</p>

![A realtime agent connecting Laravel, canonical state, and provider services](docs/readme/header.png)

Laravel Realtime Agent is an installable Composer package for Laravel 12 and 13. It keeps audio and realtime events close to the provider while every consequential operation—state mutation, goal completion, tool execution, authorization, confirmation, and UI action—passes through Laravel.

The package ships a private, framework-neutral TypeScript runtime in the same Composer distribution. The default provider is deterministic and free: no API key is required to develop or run the standard test suite.

## Why this package exists

A conversational provider is good at low-latency voice. It should not be the authority for your application.

This package separates the two concerns:

- the provider carries realtime media and translates vendor events;
- Laravel owns the canonical snapshot, revision checks, policies, tool broker, goals, audit log, and signed UI commands;
- the browser exposes only a semantic `Surface`, never a raw DOM dump or arbitrary selectors;
- OpenAI and ElevenLabs IDs remain inside their adapters, outside application code.

![The direct media path and Laravel-controlled state path](docs/readme/architecture.png)

## Requirements

- PHP 8.2 or newer
- Laravel 12 or 13
- Node.js 20 or newer when bundling the browser client
- a database supported by Laravel
- HTTPS for browser microphone access

## Install in a Laravel application

For the current local checkout:

```bash
laravel new realtime-agent-demo
cd realtime-agent-demo
composer config repositories.realtime-agent path /Users/marco/packages/agents-full-duplex-ui-bridge
composer require agents-full-duplex/laravel-realtime-agent:@dev
php artisan realtime-agent:install
php artisan migrate
npm install ./vendor/agents-full-duplex/laravel-realtime-agent
npm run build
```

Set the application to the Fake Provider in `.env`:

```dotenv
APP_URL=https://realtime-agent-demo.test
REALTIME_AGENT_PROVIDER=fake
```

Open the project as `realtime-agent-demo.test` with Laravel Herd and enable HTTPS for that site. Do not add OpenAI or ElevenLabs credentials for the standard test path.

The install command publishes:

- `config/realtime-agent.php`;
- four timestamped migrations, without duplicating an already installed migration;
- the compiled browser runtime under `public/vendor/realtime-agent`.

Use `--force` only when you intentionally want to refresh published configuration, migrations, and assets.

## Create a session

Sessions are created by trusted application code, never by a generic browser endpoint:

```php
use AgentsFullDuplex\RealtimeAgent\Enums\InteractionLevel;
use AgentsFullDuplex\RealtimeAgent\Facades\RealtimeAgent;
use AgentsFullDuplex\RealtimeAgent\Goal;
use AgentsFullDuplex\RealtimeAgent\Tools\ExecuteUiAction;
use AgentsFullDuplex\RealtimeAgent\Tools\RuntimeStateGet;
use AgentsFullDuplex\RealtimeAgent\Tools\UpdateGoal;
use AgentsFullDuplex\RealtimeAgent\Tools\UpdateWorkingMemory;

$started = RealtimeAgent::make('lessons.teacher')
    ->instructions('Teach the current lesson and complete goals only with evidence.')
    ->context([
        'lesson_id' => $lesson->getKey(),
        'source_text' => $lesson->source_text,
    ])
    ->goals([
        Goal::make('topic_1')->label('Photosynthesis')->required()->completionByAgent(true),
        Goal::make('topic_2')->label('Light-dependent phase')->required()->completionByAgent(true),
        Goal::make('topic_3')->label('Calvin cycle')->required()->completionByAgent(true),
    ])
    ->tools([
        RuntimeStateGet::class,
        UpdateGoal::class,
        UpdateWorkingMemory::class,
        ExecuteUiAction::class,
    ])
    ->surface('lesson.show')
    ->interactionLevel(InteractionLevel::Guide)
    ->allowAgentGoals(false)
    ->startFor($request->user());

return view('lessons.show', [
    'agentConnection' => $started->connection(),
]);
```

`startFor()` returns a `StartedAgentSession`. It exposes the session ID, initial state, client connection descriptor, and the normal executable session methods.

## Connect the browser runtime

Expose the descriptor as JSON in Blade:

```blade
<meta name="csrf-token" content="{{ csrf_token() }}">
<script id="agent-connection" type="application/json">
    @json($agentConnection)
</script>
```

Then register a semantic Surface and connect the client:

```ts
import {
  DomSurfaceAdapter,
  FakeRealtimeDriver,
  LaravelControlTransport,
  RealtimeAgentClient,
  SurfaceRegistry,
} from '@agents-full-duplex/realtime-agent-client';

const descriptor = JSON.parse(
  document.querySelector('#agent-connection')!.textContent!,
);
const surfaces = new SurfaceRegistry();

new DomSurfaceAdapter(document, surfaces).register('lesson.show', 'Lesson');

const client = new RealtimeAgentClient(
  surfaces,
  new FakeRealtimeDriver(),
  new LaravelControlTransport(descriptor.session_id),
);

await client.connect(descriptor);
```

The declarative adapter reads only elements explicitly marked with `data-agent-*` attributes:

```html
<input
  data-agent-id="lesson.notes"
  data-agent-label="Lesson notes"
  data-agent-actions="focus,set_value"
>
```

Programmatic registration is available through `client.surface.register(...)`. The public client also exposes `connect`, `disconnect`, `sendText`, `refreshState`, `finish`, and `on`.

![A semantic Surface exposing approved components and excluding executable input](docs/readme/surface.png)

## Tools, confirmations, and state

Application tools use one canonical definition regardless of provider:

```php
use AgentsFullDuplex\RealtimeAgent\Confirmation;
use AgentsFullDuplex\RealtimeAgent\Enums\ToolTarget;
use AgentsFullDuplex\RealtimeAgent\Schema;
use AgentsFullDuplex\RealtimeAgent\Tool;

Tool::make('customer.search')
    ->description('Search customers available to the current user.')
    ->input([
        'query' => Schema::string()->required(),
    ])
    ->target(ToolTarget::Server)
    ->handler(SearchCustomers::class)
    ->authorize(CustomerSearchPolicy::class)
    ->confirmation(Confirmation::always());
```

Built-in tools are opt-in per session:

- `runtime.state.get`
- `working_memory.update`
- `goal.update`
- `ui.action`
- `session.finish`

State writes use optimistic `base_revision` checks. A stale mutation returns HTTP 409 with the current state. Policies return 403, invalid schemas return 422, and expired UI commands return 410. Tool calls are idempotent, events are append-only, sensitive arguments are redacted from audit by default, and UI commands carry a short-lived server signature plus a one-use nonce.

Control routes are mounted under `/realtime-agent` with `web` and `auth` middleware by default. Both prefix and middleware are configurable. Session ownership is checked again inside the controller; an application can set `security.authorization_ability` to a Laravel Gate ability for custom authorization.

## Providers

### Fake

`fake` is the default. It performs no external HTTP calls and exposes deterministic provider events for unit, feature, and browser tests.

### OpenAI Realtime

Set these only when deliberately testing the live adapter:

```dotenv
REALTIME_AGENT_PROVIDER=openai
OPENAI_API_KEY=...
OPENAI_REALTIME_MODEL=gpt-realtime
OPENAI_REALTIME_VOICE=marin
```

Laravel forwards the SDP offer to OpenAI while audio and provider events flow over browser WebRTC. Function calls are normalized through the Tool Broker, and long sessions renegotiate while retaining the Laravel session state. See the official [OpenAI Realtime WebRTC guide](https://developers.openai.com/api/docs/guides/realtime-webrtc).

### ElevenLabs

```dotenv
REALTIME_AGENT_PROVIDER=elevenlabs
ELEVENLABS_API_KEY=...
ELEVENLABS_AGENT_ID=...
```

Laravel returns a signed WebSocket URL. Canonical tool schemas are materialized as ElevenLabs client tools and cached by schema hash in `realtime_agent_provider_tools`. Surface updates use contextual updates, and client tool calls always return through Laravel. See the official [signed URL](https://elevenlabs.io/docs/eleven-agents/api-reference/conversations/get-signed-url) and [client event](https://elevenlabs.io/docs/eleven-agents/customization/events/client-to-server-events) references.

No live provider test runs in the standard suite or in CI.

## Test the package itself

From this repository:

```bash
composer install
npm install
npx playwright install chromium

composer check
npm run check
npm run test:e2e
```

The commands cover:

- PHPUnit and Orchestra Testbench for state revisions, database persistence, goals, ownership, authorization boundaries, confirmations, signed UI commands, provider normalization, and idempotency;
- Pint and Larastan for PHP style and static analysis;
- TypeScript build, JSON Schema synchronization, Vitest, and jsdom for Surfaces and the command executor;
- Playwright for the full Fake Provider scenario: three goals, three UI updates, then a completed session.

![The deterministic Fake Provider completing three goals in the browser test](docs/readme/testing.png)

You can inspect configuration readiness without contacting a provider:

```bash
php artisan realtime-agent:doctor
```

With the Fake Provider, the expected result is `ready (no credentials required)`.

## Test in a real Laravel app

After the local installation above:

1. Create a controller action that starts a Fake Provider session with three declared goals.
2. Pass `$started->connection()` to a Blade page.
3. Add a CSRF meta tag and at least one `data-agent-id` element.
4. Instantiate `FakeRealtimeDriver`, `LaravelControlTransport`, and `RealtimeAgentClient` as shown above.
5. Open `https://realtime-agent-demo.test` through Herd.
6. Verify `php artisan realtime-agent:doctor`, then inspect the Network panel: control calls stay under `/realtime-agent`, and no provider domain is contacted.

For a fully deterministic reference, open [examples/fake-lesson.html](examples/fake-lesson.html) through the Playwright test server by running `npm run test:e2e`.

## Architecture map

```text
Browser microphone ───────────────> realtime provider
Browser provider events <───────── realtime provider

Browser Surface ──> Laravel routes ──> State / Goal engines
Provider tool call ─> Tool Broker ───> policy + confirmation
Laravel ── signed semantic command ──> browser UI executor
Browser result ──> Laravel audit ─────> normalized provider result
```

The database is the only durable state store in v0.1. Redis, Reverb, server-side sideband connections, and dedicated React/Vue/Livewire adapters are intentionally deferred; the core browser API already works with those frameworks through programmatic Surface registration.

## Security model

- Browser, provider, route data, Surface snapshots, and tool arguments are untrusted.
- Only registered tool names, semantic targets, and action names are accepted.
- Raw CSS selectors, HTML, scripts, and JavaScript fields are rejected from Surface input.
- Authorization runs at session and tool level.
- UI commands expire, are signed with `APP_KEY`, and are removed after completion.
- Provider keys stay on Laravel; they are never serialized into the client descriptor.
- Current state is compact; the event stream remains append-only for audit and debugging.

## Version scope

This repository currently implements the v0.1 development line. See [CHANGELOG.md](CHANGELOG.md) for changes and [LICENSE.md](LICENSE.md) for licensing.
