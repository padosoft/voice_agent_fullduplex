# Laravel Realtime Agent

A provider-neutral Laravel package skeleton for realtime agents with an authoritative state, semantic UI Surfaces, typed tools, first-class goals, and an auditable event stream.

This repository implements the first slice recommended by the architecture specification: the canonical PHP core, the browser bridge, and a deterministic fake provider. The OpenAI and ElevenLabs classes are explicit extension points and intentionally fail with a clear message until their transport phases are implemented.

## Try it now

Requirements: PHP 8.2+, Composer, Node.js 20+.

```bash
composer install
composer test
composer demo

npm install
npm run check
npm run demo
```

`composer demo` runs a three-goal lesson from revision 1 to automatic completion with no API key. `npm run demo` executes a registered semantic `highlight` action and prints the fresh Surface snapshot. The test suites cover optimistic revision conflicts, tool idempotency, goal completion, event audit, semantic UI authorization, command expiry and nonce replay.

## Install in a Laravel application

Until the package is published, add this checkout as a Composer path repository:

```bash
composer config repositories.realtime-agent path /Users/marco/packages/agents-full-duplex-ui-bridge
composer require agents-full-duplex/laravel-realtime-agent:@dev
php artisan vendor:publish --tag=realtime-agent-config
php artisan migrate
```

Laravel package discovery registers the service provider and facade automatically. The default state driver is `database`; the default provider is `fake` so development never incurs provider cost.

To use the unpublished browser runtime in a Laravel application, publish its TypeScript sources and import them through your existing Vite entry point:

```bash
php artisan vendor:publish --tag=realtime-agent-assets
```

```ts
import { SurfaceRegistry } from "./vendor/realtime-agent/client";
```

## Define a session

```php
use AgentsFullDuplex\RealtimeAgent\Enums\InteractionLevel;
use AgentsFullDuplex\RealtimeAgent\Facades\RealtimeAgent;
use AgentsFullDuplex\RealtimeAgent\Goal;
use AgentsFullDuplex\RealtimeAgent\Tools\RuntimeStateGet;
use AgentsFullDuplex\RealtimeAgent\Tools\UpdateGoal;
use AgentsFullDuplex\RealtimeAgent\Tools\UpdateWorkingMemory;

$session = RealtimeAgent::make('lessons.teacher')
    ->instructions('Teach the current lesson.')
    ->context(['lesson_id' => $lesson->id])
    ->goals([
        Goal::make('topic_1')
            ->label('Understand photosynthesis')
            ->required()
            ->completionByAgent(requiresEvidence: true),
    ])
    ->tools([
        RuntimeStateGet::class,
        UpdateGoal::class,
        UpdateWorkingMemory::class,
    ])
    ->surface('lesson.show')
    ->interactionLevel(InteractionLevel::Guide)
    ->startFor($request->user());

return response()->json($session->connection());
```

Every state-changing tool call carries `base_revision`. A stale mutation is rejected, each accepted mutation advances the revision exactly once, and retries with the same idempotency key return the original result.

## Register a browser Surface

Programmatic registration:

```ts
import { SurfaceRegistry, UiCommandExecutor } from "@agents-full-duplex/realtime-agent-client";

const surfaces = new SurfaceRegistry();

surfaces.register({
  id: "customers.edit",
  snapshot: () => ({
    title: "Edit customer",
    components: {
      "customer.email": {
        type: "email",
        label: "Email",
        state: { value: emailInput.value },
        actions: ["focus", "set_value"],
      },
    },
  }),
  actions: {
    focus: ({ target }) => emailInput.focus(),
    set_value: ({ value }) => { emailInput.value = String(value); },
  },
});

const executor = new UiCommandExecutor(sessionId, surfaces);
```

For Blade, Livewire or vanilla pages, `DomSurfaceAdapter` scans only elements annotated with `data-agent-id`, `data-agent-label`, and `data-agent-actions`. It never sends the raw DOM and never accepts CSS selectors or executable code from the agent.

## What is implemented

- Fluent agent/session definition API and Laravel facade.
- Canonical state snapshot with explicit ownership boundaries.
- In-memory and transactional database state stores.
- Optimistic revisions and append-only canonical events.
- Goal engine with evidence rules and automatic finish policy.
- Typed tool registry, schema validation, authorization hook, confirmation gate, and idempotency.
- Built-in `runtime.state.get`, `working_memory.update`, `goal.update`, `ui.action`, and `session.finish` tools.
- Framework-neutral TypeScript Surface registry, declarative DOM adapter, command executor, provider contract, and fake driver.
- OpenAI/ElevenLabs server and browser adapter boundaries for the next phases.

## Deliberate next phases

The package does not claim live voice-provider support yet. Next work is the Laravel control HTTP/broadcast channel, then OpenAI WebRTC, then ElevenLabs WebSocket/tool-resource materialization. Keeping those transports outside the MVP lets the canonical state and security contracts be tested without vendor-specific behavior.
