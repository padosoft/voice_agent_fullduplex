# Anonymous integration case studies

These fictionalized case studies describe reusable integration patterns. They do not identify, document, or claim compatibility with any external product or repository. All model, service, route, event, component, and Surface names are illustrative placeholders that must be mapped to the target application's real architecture after inspection.

## Case study A: adaptive-learning session

### Scenario

An authenticated Laravel application uses Inertia and React to run lessons. A student-owned learning session already contains a lesson plan, current topic, ordered concepts, chat history, quizzes, retrieval tools, and usage limits. OpenAI and ElevenLabs are currently integrated through separate browser components.

The integration goal is to use one provider-neutral realtime session while preserving the application's learning rules and projections.

### Server integration

Create a focused application service, for example `StartLearningRealtimeAgent`. It receives an already-authorized domain session and current user. Store the returned package session ID in the domain session metadata so a reload can resume rather than create duplicate live sessions.

```php
$progress = $progressService->snapshot($studySession);
$provider = (string) config('realtime-agent.default', 'fake');

if ($provider !== 'fake') {
    $provider = $providerSelector->forSession($studySession);
}

$started = RealtimeAgent::make('case-study.learning-guide')
    ->provider($provider)
    ->instructions($contextBuilder->build($studySession))
    ->context([
        'study_session_id' => $studySession->getKey(),
        'course_id' => $studySession->course_id,
        'mode' => $studySession->mode,
        'active_concept' => $progress['current_concept'],
    ])
    ->goals(collect($progress['remaining'])->map(
        fn (array $concept) => Goal::make('concept.'.$concept['id'])
            ->label($concept['label'])
            ->required()
            ->completionByAgent(true),
    ))
    ->tools(LearningRealtimeTools::classesFor($studySession->mode))
    ->surface('learning.session')
    ->interactionLevel(InteractionLevel::Guide)
    ->allowAgentGoals(false)
    ->startFor($request->user());
```

Do not paste illustrative names into the target application. Reuse its policies, context builder, progress engine, stable concept IDs, and session-status rules.

### Tool boundary

Wrap existing domain services in canonical package tool handlers:

- advance the current concept only after the domain progress service accepts the evidence;
- retrieve course material through the application's existing authorized retrieval layer;
- record interests, learning signals, or break suggestions through focused domain actions;
- show a quiz through a semantic UI command and return the student's answer through the signed command-result flow;
- end the lesson only when the application's completion rules and the package finish policy agree.

Keep distinct tool allowlists for lesson, assessment, and any other session mode. Each mutating handler must re-check that the domain session belongs to the package session owner.

### React Surface

Replace duplicated provider transport code with one hook around `RealtimeAgentClient`. Register a Surface from current React state:

```ts
surfaces.register({
  id: 'learning.session',
  snapshot: () => ({
    title: lessonTitle,
    components: {
      'lesson.active-concept': {
        type: 'goal',
        label: activeConcept?.label ?? 'Agenda complete',
        state: { activeConcept, completedConceptIds },
        actions: [],
      },
      'chat.composer': {
        type: 'composer',
        label: 'Student message',
        state: { enabled: !isBusy },
        actions: ['focus'],
      },
      'lesson.quiz': {
        type: 'quiz',
        label: 'Lesson quiz',
        state: { visible: quizVisible },
        actions: ['activate'],
      },
    },
  }),
  actions: {
    focus: () => composerRef.current?.focus(),
    activate: ({ target }) => {
      if (target === 'lesson.quiz') setQuizVisible(true);
    },
  },
});
```

The server-issued descriptor selects the Fake, OpenAI, or ElevenLabs driver. A browser field must never override the provider persisted in the session definition.

### Transcript and billing projection

Use one canonical realtime write path:

- package messages are the realtime transcript/audit source;
- an `AgentMessageRecorded` listener projects completed rows into the application's existing lesson-turn model;
- an `AgentUsageRecorded` listener feeds the existing quota and billing projection;
- old browser transcript/heartbeat writes are removed only after equivalent projection coverage exists;
- goal-completion events update domain lesson progress through application listeners.

Keep the package usage records as the provider evidence store. Do not add projected billing rows to package rows as if they represented separate consumption.

### Acceptance test

With the Fake Provider, prove that concepts become ordered goals, the agent cannot skip the active concept, quiz presentation is limited to registered Surface actions, transcript survives reload, usage projects once, another user receives `403`, and the last accepted concept completes both the package mission and domain session.

## Case study B: connected-environment controller

### Scenario

An authenticated Laravel/Inertia application controls rooms, devices, scenes, and routines. It already has an authorization policy, a revisioned observed/desired state, tracked commands, idempotent tool records, a React voice concierge, and possibly a separately authenticated wall-panel experience.

The integration goal is to centralize realtime transport and audit without weakening physical-control safeguards.

### Server integration

Create an application action such as `StartEnvironmentRealtimeAgent`. Authorize the current user for the environment before creating the package session.

```php
Gate::authorize('control', $environment);

$started = RealtimeAgent::make('case-study.environment-concierge')
    ->provider('fake')
    ->instructions((string) config('assistant.instructions'))
    ->context([
        'environment_id' => $environment->getKey(),
        'area_id' => $visibleArea?->getKey(),
        'locale' => $locale,
        'state_revision' => $publicState['revision'],
    ])
    ->goals([])
    ->tools(EnvironmentRealtimeTools::classes())
    ->surface('environment.overview')
    ->interactionLevel(InteractionLevel::Operate)
    ->allowAgentGoals(false)
    ->startFor($request->user());
```

Do not serialize complete floor plans, image URLs, connector credentials, or unrestricted telemetry. The agent should call an authorized state-reading tool whenever it needs current data.

### Tool boundary

Wrap the application's existing actions instead of implementing device protocols in the package handlers:

- read a filtered public state snapshot;
- submit a change with expected device/control revisions;
- read confirmed progress for a tracked change;
- request an existing scene scoped to the current environment;
- create a routine draft that cannot be confirmed by the agent itself.

Resolve the environment identifier from persisted session context, re-run the control policy, and pass the package idempotency key into the domain command ID. Require confirmation for consequential physical changes according to product policy. A successful tool result means accepted or tracked; it is not proof that a physical device reached the requested state.

### React Surface

Keep the existing permission, visibility, timeout, locale, and presentation UI while replacing raw provider transport code with `RealtimeAgentClient`:

```ts
surfaces.register({
  id: 'environment.overview',
  snapshot: () => ({
    title: environmentName,
    components: Object.fromEntries(
      visibleDevices.map((device) => [
        `device.${device.id}`,
        {
          type: 'device',
          label: device.name,
          state: publicDeviceState(device),
          actions: ['focus'],
        },
      ]),
    ),
  }),
  actions: {
    focus: ({ target }) => selectDevice(target.replace('device.', '')),
  },
});
```

Surface actions may change presentation only. Physical device commands stay in server tools behind policy, validation, revision checks, and confirmation.

If a wall panel is authenticated differently from a normal Eloquent user, do not disable package authorization. Integrate the normal user flow first, then design a deliberate authenticated principal/Gate boundary for panels or retain their isolated transport.

### Audit migration

Use package sessions as the realtime transport/audit aggregate. If the application retains its voice-session model for quotas or operational dashboards, map it explicitly one-to-one and project package events into it. Ensure only one tool record can execute a given command.

Project `AgentUsageRecorded` into the application's usage/quota layer while retaining the package distinction between estimated and provider-final costs.

### Acceptance test

With faked device connectors and the Fake Provider, prove that an authorized operator can read filtered state, an outsider cannot connect, stale revisions fail, duplicate tool calls create one tracked change, consequential actions require the intended confirmation, drafts cannot self-confirm, the Surface cannot issue physical commands, disconnect stops transport, and audit contains one tool/cost lifecycle.

## Case study C: revisioned creative workspace

### Scenario

An authenticated Laravel/Inertia creative application guides a user through a versioned method made of phases, objectives, and cards. It already has a project policy, an authoritative state snapshot/version, a bounded conversation context, a workflow-progress calculator, mutation contracts, background projections, broadcasts, and a React board.

The integration goal is to add provider-neutral realtime conversation while preserving the domain state engine and conflict handling.

### Server integration

Create an application service such as `StartCreativeRealtimeAgent`. Require view authorization for read-only tools and update authorization before enabling mutations. If the selected method does not declare realtime capability, do not create a session.

```php
$workflow = $workflowProgress->forProject($creativeProject);

$started = RealtimeAgent::make('case-study.creative-method')
    ->provider('fake')
    ->instructions($conversationContext->prompt($conversation))
    ->context([
        'project_id' => $creativeProject->getKey(),
        'method' => $creativeProject->methodVersion->slug,
        'state_version' => $creativeProject->state_version,
        'active_phase' => $workflow['active_phase'],
        'active_objective' => $workflow['active_objective'],
        'next_card' => $workflow['next_card'],
    ])
    ->goals([
        Goal::make('active_objective')
            ->label((string) ($workflow['active_objective']['title'] ?? 'Complete the active method step'))
            ->required()
            ->completionByAgent(true),
    ])
    ->tools(CreativeRealtimeTools::classesFor($creativeProject))
    ->surface('creative.board')
    ->interactionLevel(InteractionLevel::Guide)
    ->allowAgentGoals(false)
    ->startFor($request->user());
```

A realtime mission should normally cover the current objective, not remain open for an unbounded project lifetime. When the workflow advances, finish the current package session and deliberately create or resume the next one, unless a tested application policy safely refreshes goals.

### Tool and revision boundary

Wrap the existing domain gateway and preserve its allowlist for reading state/cards, selecting focus, updating cards, synchronizing state, managing scoped items, and completing workflow steps.

Mutations must still flow through the application's state engine and operation contracts so its state version, checksum, evidence, revisions, projections, and broadcast events remain authoritative. Package `base_revision` protects package state; it does not replace the creative project's domain `state_version`. Validate both and return a conflict if either is stale.

Read-only tools require view authorization. Mutating tools require update authorization. Destructive, archive, or reorder operations use the application's confirmation policy.

### React Surface

Replace the raw provider hook with one package-backed client while keeping the chat and board UI:

```ts
surfaces.register({
  id: 'creative.board',
  snapshot: () => ({
    title: projectTitle,
    components: Object.fromEntries(
      projectedCards.map(({ card, state }) => [
        `card.${card.id}`,
        {
          type: 'method-card',
          label: card.title,
          state: {
            status: state.status,
            completeness: state.completeness,
            focused: focusedCardId === card.id,
          },
          actions: ['focus'],
        },
      ]),
    ),
  }),
  actions: {
    focus: ({ target }) => setFocus(target.replace('card.', '')),
  },
});
```

The Surface carries visible semantic state and presentation actions. Project mutations remain server tools. Replace custom provider context events with bounded Surface synchronization only after tests prove the provider still receives the current state version and focus.

### Conversation and audit projection

Keep one visible conversation history:

- package messages are the realtime ingress/audit source;
- `AgentMessageRecorded` projects completed rows through the application's conversation manager;
- package tool handlers continue producing domain steps, evidence, revisions, and patches;
- `AgentUsageRecorded` feeds metrics or accounting while the package retains raw provider evidence and rate snapshots;
- legacy transcript endpoints are removed only after replay, correction, and idempotency tests cover the projection.

Retain a domain conversation-session model only when its warning, expiry, consent, or reconnect semantics are still needed. If retained, map it explicitly to one package session instead of creating a second provider call.

### Acceptance test

With the Fake Provider, prove that only an authorized owner can connect, unsupported methods are rejected, the current phase/objective/card enters context, read tools cannot mutate, stale package or domain revisions conflict, one tool call produces one operation and patch, the Surface focuses only declared cards, transcript projects once, broadcasts remain compatible, and completion advances exactly the intended objective session.
