# Integration manual

Use this manual when installing `agents-full-duplex/laravel-realtime-agent` in an existing Laravel application. Adapt model and field names to the target domain; keep the package's trust boundaries unchanged.

## 1. Compatibility and architecture

The target application needs PHP 8.2+, Laravel 12 or 13, Node.js 20+ for bundling the browser client, a Laravel-supported database, authentication, and HTTPS for microphone access.

The realtime provider owns the low-latency audio transport. Laravel remains authoritative for session state, revisions, goals, tools, authorization, confirmations, semantic UI commands, ordered transcript, and cost audit. A session is created by trusted application PHP code and belongs to the authenticated Eloquent user passed to `startFor()`.

## 2. Install the package

### Composer registry

For the stable `0.2` release line:

```bash
composer require agents-full-duplex/laravel-realtime-agent:^0.2
php artisan realtime-agent:install
php artisan migrate
npm install ./vendor/agents-full-duplex/laravel-realtime-agent
npm run build
```

### Local path repository

Use Marco's local checkout only when the target must test package changes that have not been released:

```bash
composer config repositories.realtime-agent path /Users/marco/packages/agents-full-duplex-ui-bridge
composer require agents-full-duplex/laravel-realtime-agent:@dev
php artisan realtime-agent:install
php artisan migrate
npm install ./vendor/agents-full-duplex/laravel-realtime-agent
npm run build
```

Package discovery registers the service provider and facade. The install command publishes `config/realtime-agent.php`, six timestamped migrations, and the compiled browser runtime under `public/vendor/realtime-agent`. It does not run migrations. Re-running it does not duplicate existing files; use `--force` only for an intentional refresh.

Commit the target application's `composer.json`, package lockfiles, published configuration, and migrations according to that application's policy. Do not commit provider secrets.

## 3. Choose a provider

Start with the Fake Provider:

```dotenv
REALTIME_AGENT_PROVIDER=fake
```

OpenAI GPT-Live:

```dotenv
REALTIME_AGENT_PROVIDER=openai
OPENAI_API_KEY=
OPENAI_LIVE_MODEL=gpt-live-1
OPENAI_LIVE_BACKEND_MODEL=gpt-5.6-terra
OPENAI_LIVE_VOICE=marin
OPENAI_LIVE_STORE=false
```

ElevenLabs:

```dotenv
REALTIME_AGENT_PROVIDER=elevenlabs
ELEVENLABS_API_KEY=
ELEVENLABS_AGENT_ID=
```

Gemini Live through the Gemini Developer API:

```dotenv
REALTIME_AGENT_PROVIDER=gemini
GEMINI_API_KEY=
GEMINI_LIVE_MODEL=gemini-3.8-live
GEMINI_LIVE_VOICE=Puck
GEMINI_LIVE_TOKEN_TTL_SECONDS=1800
GEMINI_LIVE_NEW_SESSION_TTL_SECONDS=60
GEMINI_LIVE_CONTEXT_COMPRESSION_TRIGGER_TOKENS=25000
GEMINI_LIVE_CONTEXT_COMPRESSION_WINDOW_TOKENS=8000
```

xAI Grok Voice:

```dotenv
REALTIME_AGENT_PROVIDER=xai
XAI_API_KEY=
XAI_VOICE_MODEL=grok-voice-latest
XAI_VOICE=eve
XAI_VOICE_VAD=server_vad
XAI_VOICE_CLIENT_SECRET_TTL_SECONDS=300
```

After changing environment values:

```bash
php artisan config:clear
php artisan realtime-agent:doctor
```

Never send permanent API keys to Blade, JavaScript, a Surface snapshot, or a client connection descriptor. OpenAI's key is used server-side to exchange the browser SDP offer. ElevenLabs receives a short-lived signed URL from Laravel. Gemini and xAI descriptors contain a short-lived client credential, so serialize them only into the authenticated page that immediately connects, and never log, cache, store, or pass them to another user.

The provider can also be selected for one definition with `->provider('fake')`, `->provider('openai')`, `->provider('elevenlabs')`, `->provider('gemini')`, or `->provider('xai')`. The provider stored in the session definition is authoritative.

## 4. Create the session in application code

Create sessions in an authenticated controller or application service. Do not expose a generic endpoint that accepts arbitrary instructions, tools, or owners from the browser.

```php
<?php

namespace App\Http\Controllers;

use AgentsFullDuplex\RealtimeAgent\Enums\InteractionLevel;
use AgentsFullDuplex\RealtimeAgent\Facades\RealtimeAgent;
use AgentsFullDuplex\RealtimeAgent\Goal;
use AgentsFullDuplex\RealtimeAgent\Tools\ExecuteUiAction;
use AgentsFullDuplex\RealtimeAgent\Tools\FinishSession;
use AgentsFullDuplex\RealtimeAgent\Tools\RuntimeStateGet;
use AgentsFullDuplex\RealtimeAgent\Tools\UpdateGoal;
use AgentsFullDuplex\RealtimeAgent\Tools\UpdateWorkingMemory;
use App\Models\Document;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

final class DocumentAgentController extends Controller
{
    public function show(Request $request, Document $document)
    {
        Gate::authorize('view', $document);

        $started = RealtimeAgent::make('askmydoc.document-guide')
            ->instructions('Answer only from the supplied document context. State when the source is insufficient.')
            ->context([
                'document_id' => $document->getKey(),
                'title' => $document->title,
                // Map this to the target application's safe, bounded text representation.
                'source_text' => $document->content,
            ])
            ->goals([
                Goal::make('understand_request')
                    ->label('Understand the user request')
                    ->required()
                    ->completionByAgent(true),
                Goal::make('answer_from_document')
                    ->label('Answer using document evidence')
                    ->required()
                    ->completionByAgent(true),
            ])
            ->tools([
                RuntimeStateGet::class,
                UpdateWorkingMemory::class,
                UpdateGoal::class,
                ExecuteUiAction::class,
                FinishSession::class,
            ])
            ->surface('documents.show')
            ->interactionLevel(InteractionLevel::Guide)
            ->allowAgentGoals(false)
            ->startFor($request->user());

        return view('documents.show', [
            'document' => $document,
            'agentConnection' => $started->connection(),
        ]);
    }
}
```

Place the controller route behind `auth`. `startFor()` returns the session ID, initial canonical state, and a provider-safe `ClientConnectionDescriptor`.

### Definition choices

- `instructions(...)`: durable behavior for reconnection and audit; do not include secrets.
- `context(...)`: application-owned initial data. Bound large documents or use application tools for retrieval rather than serializing an entire corpus.
- `goals(...)`: explicit work items. Required goals work with the default `AllRequiredGoalsCompleted` finish policy.
- `tools(...)`: an allowlist for this session; built-in tools are not enabled implicitly.
- `surface(...)`: the one semantic browser Surface expected by the session.
- `interactionLevel(...)`: `Observe` refuses UI actions; use `Guide` or `Operate` only when the declared experience should permit registered semantic actions. These levels do not replace tool authorization or confirmation.
- `allowAgentGoals(false)`: prevents the model from inventing new goals.
- `stateOwnership([...])`: reserve paths such as `/working_memory` or `/mission/goals` for `application` or `agent` when the application needs stricter ownership.
- `finishWhen(CustomPolicy::class)`: replace the default required-goals policy with a class implementing `FinishPolicyContract`.

Persisted custom tool handlers and authorizers must be container-resolvable class strings, not closures.

## 5. Expose the descriptor in Blade

```blade
<meta name="csrf-token" content="{{ csrf_token() }}">

<script id="agent-connection" type="application/json">
    @json($agentConnection)
</script>

<button type="button" id="agent-connect">Start assistant</button>

<article
    data-agent-id="document.content"
    data-agent-label="Document content"
    data-agent-actions="focus,highlight"
>
    {{-- Existing application UI --}}
</article>

@vite('resources/js/realtime-agent.ts')
```

Only mark information the provider is allowed to observe. `data-agent-id` is a stable semantic identifier, `data-agent-label` is human-readable context, and `data-agent-actions` is the explicit action allowlist. Never put secrets, arbitrary selectors, HTML, or JavaScript into these attributes.

## 6. Connect the browser runtime

Create `resources/js/realtime-agent.ts` or integrate the same logic into the target application's existing frontend entrypoint:

```ts
import {
  DomSurfaceAdapter,
  ElevenLabsRealtimeDriver,
  FakeRealtimeDriver,
  GeminiLiveDriver,
  LaravelControlTransport,
  OpenAILiveDriver,
  RealtimeAgentClient,
  SurfaceRegistry,
  type ConnectionDescriptor,
  type RealtimeProviderDriver,
  XaiVoiceDriver,
} from '@agents-full-duplex/realtime-agent-client';

const element = document.querySelector<HTMLScriptElement>('#agent-connection');

if (!element?.textContent) {
  throw new Error('Realtime Agent connection descriptor is missing.');
}

const descriptor = JSON.parse(element.textContent) as ConnectionDescriptor;
const surfaces = new SurfaceRegistry();

new DomSurfaceAdapter(document, surfaces).register('documents.show', 'Document');

const providers: Record<string, () => RealtimeProviderDriver> = {
  fake: () => new FakeRealtimeDriver(),
  openai: () => new OpenAILiveDriver(),
  elevenlabs: () => new ElevenLabsRealtimeDriver(),
  gemini: () => new GeminiLiveDriver(),
  xai: () => new XaiVoiceDriver(),
};

const makeProvider = providers[descriptor.provider];

if (!makeProvider) {
  throw new Error(`Unsupported realtime provider: ${descriptor.provider}`);
}

const client = new RealtimeAgentClient(
  surfaces,
  makeProvider(),
  new LaravelControlTransport(descriptor.session_id),
  {
    confirmation: async (confirmation) => {
      // Replace with the target application's accessible confirmation dialog.
      return window.confirm(`Allow ${String(confirmation.id)}?`);
    },
  },
);

client.on((event) => {
  if (event.type === 'agent.error') {
    console.error(event.error);
  }
});

document.querySelector('#agent-connect')?.addEventListener('click', async () => {
  await client.connect(descriptor);
});
```

Connect from a user gesture when using a microphone. On Marco's machines, serve the application using its Laravel Herd `<project-folder>.test` domain and existing HTTP/HTTPS setting; live microphone use requires HTTPS. Do not replace Herd with `php artisan serve` unless explicitly requested.

For non-DOM frontends, call `client.surface.register(...)` with a typed snapshot function and an action map. React, Vue, and Livewire do not need provider-specific application code.

## 7. Runtime modes and lifecycle

The public client exposes:

- `connect(descriptor)`: opens the selected provider transport and synchronizes the Surface;
- `sendText(text)`: records the user's canonical text and sends it to the provider;
- `switchToText()`: for OpenAI, gracefully closes the billed GPT-Live voice transport and continues through Laravel's Responses backend;
- `switchToVoice()`: opens a fresh OpenAI Live transport seeded with recent canonical history, retaining the same Laravel session and goals;
- `refreshState()`: reloads the authoritative revision and snapshot;
- `audit()`: returns messages, usage/cost records, tool calls, and totals for the authorized owner;
- `disconnect()`: stops the provider transport without completing the canonical session;
- `finish()`: completes the Laravel session using the current revision;
- `on(listener)`: observes normalized connection, transcript, usage, tool, state, confirmation, Surface, and error events.

OpenAI supports the voice-to-text continuation described above. ElevenLabs, Gemini and xAI can receive written messages on their current transport, but the server-side `/text` continuation endpoint is OpenAI-specific. In Gemini/xAI text mode, the browser mutes voice I/O while finalized output transcript remains available. Gemini resumes a provider session when possible; xAI reconnects with a fresh client secret and bounded canonical text history. The Fake Provider provides deterministic text responses and events for development.

## 8. Tools and authorization

Define business tools once and let the adapters map them to each provider:

```php
use AgentsFullDuplex\RealtimeAgent\Confirmation;
use AgentsFullDuplex\RealtimeAgent\Enums\ToolTarget;
use AgentsFullDuplex\RealtimeAgent\Schema;
use AgentsFullDuplex\RealtimeAgent\Tool;

Tool::make('documents.search')
    ->description('Search documents visible to the current user.')
    ->input([
        'query' => Schema::string()->required(),
    ])
    ->target(ToolTarget::Server)
    ->handler(\App\Realtime\SearchDocuments::class)
    ->authorize(\App\Realtime\AuthorizeDocumentSearch::class)
    ->confirmation(Confirmation::never());
```

The authorizer receives the session owner, tool call, and session. It must return `true` to allow execution. Use confirmation for consequential operations. Tool calls are schema-validated, rate-limited, revision-aware, and idempotent; do not bypass the Tool Broker with provider-specific handlers.

Control routes default to `/realtime-agent` with `web` and `auth` middleware. The controller also verifies that the authenticated Eloquent model is the session owner. For application-specific access, set `security.authorization_ability` in `config/realtime-agent.php` and define a Laravel Gate ability that accepts the package `AgentSession`.

Do not disable `security.require_authorization` in a user-facing environment.

## 9. Transcript, costs, and text continuity

The database stores:

- `realtime_agent_messages`: ordered user/assistant transcript rows;
- `realtime_agent_usage`: normalized usage, raw provider evidence, rate-card snapshot, currency, amount, and confidence;
- `realtime_agent_tool_calls`: requested tool, authorization/confirmation lifecycle, redacted arguments, result, revisions, and failures;
- session snapshots and append-only canonical events in the corresponding session/event tables.

Trusted PHP code can resume and audit a session:

```php
use AgentsFullDuplex\RealtimeAgent\Engine\AgentSessionManager;

$session = app(AgentSessionManager::class)->resume($sessionId);
$audit = $session->audit()->jsonSerialize();
```

The authenticated owner can call `GET /realtime-agent/sessions/{session}/audit`, or the browser can call `client.audit()`. To export data without provider coupling, listen for `AgentMessageRecorded` and `AgentUsageRecorded`.

OpenAI WebRTC cost observed by the browser is marked `estimated`; Responses token usage is a separate cost line. A trusted backend can record a provider-final cost. For ElevenLabs, call `POST /realtime-agent/sessions/{session}/audit/reconcile` after processing finishes; Laravel imports missed turns and provider-reported `cost_fiat` using the stored conversation ID.

Gemini emits `usageMetadata`; the package normalizes input/output text and audio token counts and prices them with the captured Gemini rate card. Native-audio context is compounded across turns, so leave the default context compression enabled unless the application's cost policy intentionally differs. xAI meters browser PCM input/output in cumulative rows and records direct text messages as separate units. Both are estimates, not provider-final invoices. Provider-native search, MCP, and built-in action tools remain disabled: only the PHP-declared custom functions traverse the Tool Broker.

Calling `disconnect()` ends transport only. Because transcript and state are durable, the application can later resume the Laravel session or continue it textually. Calling `finish()` marks the canonical session complete.

## 10. Verify the integration

Run at minimum:

```bash
php artisan realtime-agent:doctor
php artisan migrate:status
npm run build
php artisan test
```

With `REALTIME_AGENT_PROVIDER=fake`, verify through the application's authenticated HTTPS page that:

1. the start button connects successfully;
2. `client.sendText('Hello')` produces a deterministic assistant transcript event;
3. the Surface appears in canonical state;
4. a permitted semantic UI action succeeds and an undeclared target/action is rejected;
5. `client.audit()` includes both sides of the transcript, tool calls, and zero-cost Fake usage;
6. a different authenticated user receives `403` for the session;
7. disconnecting does not finish the session, while finishing does.

Live provider smoke tests are separate and opt-in. Do not claim they passed based only on HTTP fakes or the doctor command.

For Gemini and xAI, once an authorized local key is available, run one manual browser session per provider over HTTPS: connect with the microphone, speak one turn, verify final user/assistant transcript, invoke one declared tool, inspect `client.audit()` for a provider-specific estimated usage row, then disconnect and finish. Keep keys local and out of CI.

If the work also changes the package source, run `composer check`, `npm run check`, `npm run test:e2e`, and the validators for both bundled skills from the package root. The upstream `npm run check` gate includes schema synchronization, TypeScript build/tests, and README visual validation.

## 11. Handoff report

When finished, report:

- package source/version and files changed in the target application;
- chosen provider and why;
- session creation point and owner model;
- exposed Surface IDs/actions and enabled tools;
- authorization and confirmation decisions;
- where transcript and cost audit are consumed;
- exact commands run and their results;
- any live-provider step not run because credentials or explicit authorization were absent.
