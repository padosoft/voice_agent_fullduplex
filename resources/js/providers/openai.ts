import { EventStream } from "../events.js";
import type {
  CanonicalProviderEvent,
  ConnectionDescriptor,
  InteractionMode,
  JsonObject,
  ProviderUsageInput,
  RealtimeProviderDriver,
  ToolResult,
} from "../types.js";

type TranscriptRole = "agent" | "user";

export interface OpenAILiveTranscriptFragment extends JsonObject {
  provider_event_id: string;
  delta: string;
  start_ms: number;
  end_ms: number;
}

interface TranscriptRow {
  id: string;
  role: TranscriptRole;
  startMs: number;
  endMs: number;
  fragments: Array<OpenAILiveTranscriptFragment & { arrival_index: number }>;
  emitted: boolean;
}

export interface OpenAILiveTranscriptPush {
  rowId: string;
  correction?: Extract<CanonicalProviderEvent, { type: "agent.transcript.final" }>;
}

const asObject = (value: unknown): JsonObject => value !== null && typeof value === "object"
  ? value as JsonObject
  : {};

const asFiniteNumber = (value: unknown, fallback = 0): number => {
  const number = Number(value);

  return Number.isFinite(number) ? number : fallback;
};

export function normalizeOpenAIResponseUsage(usage: JsonObject): Record<string, number> {
  const input = asObject(usage.input_token_details ?? usage.input_tokens_details);
  const output = asObject(usage.output_token_details ?? usage.output_tokens_details);
  const cached = asObject(input.cached_tokens_details);

  return {
    input_text_tokens: asFiniteNumber(input.text_tokens ?? usage.input_tokens),
    cached_input_text_tokens: asFiniteNumber(input.cached_tokens ?? cached.text_tokens),
    input_audio_tokens: asFiniteNumber(input.audio_tokens),
    cached_input_audio_tokens: asFiniteNumber(cached.audio_tokens),
    input_image_tokens: asFiniteNumber(input.image_tokens),
    cached_input_image_tokens: asFiniteNumber(cached.image_tokens),
    output_text_tokens: asFiniteNumber(output.text_tokens ?? usage.output_tokens),
    output_audio_tokens: asFiniteNumber(output.audio_tokens),
  };
}

export function openAIResponseCreateEvent(_mode?: InteractionMode, eventId?: string): JsonObject {
  return eventId
    ? { type: "response.create", event_id: eventId }
    : { type: "response.create" };
}

export function normalizeOpenAILiveDurationUsage(
  event: JsonObject,
  providerSessionId: string,
  model = "gpt-live-1",
): ProviderUsageInput | null {
  const usage = asObject(event.usage);
  const seconds = asFiniteNumber(usage.seconds, -1);

  if (seconds < 0 || !providerSessionId) return null;

  const final = event.type === "session.closed";

  return {
    provider: "openai",
    provider_event_id: String(event.event_id ?? `${providerSessionId}:duration`),
    idempotency_key: `openai-live-duration:${providerSessionId}`,
    kind: "duration",
    model,
    units: { duration_seconds: seconds },
    raw: {
      usage,
      context_window: asObject(event.context_window),
      reason: event.reason ?? null,
      cumulative_snapshot: true,
      final,
    },
  };
}

/**
 * Groups GPT-Live transcript fragments into revisable display/audit rows while
 * retaining every original delta and its provider timeline interval.
 */
export class OpenAILiveTranscriptAssembler {
  private readonly rows: Record<TranscriptRole, TranscriptRow[]> = { agent: [], user: [] };
  private arrivalIndex = 0;

  constructor(
    private readonly gapMs = 1_200,
    private readonly maximumFragmentsPerRow = 128,
  ) {}

  push(role: TranscriptRole, event: JsonObject): OpenAILiveTranscriptPush | null {
    const delta = String(event.delta ?? "");

    if (!delta) return null;

    const startMs = asFiniteNumber(event.start_ms);
    const endMs = Math.max(startMs, asFiniteNumber(event.end_ms, startMs));
    const eventId = String(event.event_id ?? `fragment_${++this.arrivalIndex}`);
    const rows = this.rows[role];
    let row = this.closestRow(rows, startMs, endMs);

    if (!row || row.fragments.length >= this.maximumFragmentsPerRow) {
      row = {
        id: `openai_live_${role}_${eventId}`.slice(0, 240),
        role,
        startMs,
        endMs,
        fragments: [],
        emitted: false,
      };
      rows.push(row);
    }

    if (!row.fragments.some((fragment) => fragment.provider_event_id === eventId)) {
      row.fragments.push({
        provider_event_id: eventId,
        delta,
        start_ms: startMs,
        end_ms: endMs,
        arrival_index: ++this.arrivalIndex,
      });
      row.fragments.sort((left, right) => left.start_ms - right.start_ms || left.arrival_index - right.arrival_index);
      row.startMs = Math.min(row.startMs, startMs);
      row.endMs = Math.max(row.endMs, endMs);
    }

    return {
      rowId: row.id,
      correction: row.emitted ? this.eventFor(row, "corrected") : undefined,
    };
  }

  finalize(rowId: string): Extract<CanonicalProviderEvent, { type: "agent.transcript.final" }> | null {
    const row = this.find(rowId);

    if (!row || row.emitted) return null;

    row.emitted = true;

    return this.eventFor(row, "completed");
  }

  flushPending(): Array<Extract<CanonicalProviderEvent, { type: "agent.transcript.final" }>> {
    const events: Array<Extract<CanonicalProviderEvent, { type: "agent.transcript.final" }>> = [];

    for (const row of [...this.rows.user, ...this.rows.agent]) {
      const event = this.finalize(row.id);

      if (event) events.push(event);
    }

    return events;
  }

  private closestRow(rows: TranscriptRow[], startMs: number, endMs: number): TranscriptRow | undefined {
    return [...rows]
      .reverse()
      .find((row) => startMs <= row.endMs + this.gapMs && endMs >= row.startMs - this.gapMs);
  }

  private find(rowId: string): TranscriptRow | undefined {
    return [...this.rows.user, ...this.rows.agent].find((row) => row.id === rowId);
  }

  private eventFor(
    row: TranscriptRow,
    status: "completed" | "corrected",
  ): Extract<CanonicalProviderEvent, { type: "agent.transcript.final" }> {
    const fragments = row.fragments.map(({ arrival_index: _arrivalIndex, ...fragment }) => fragment);

    return {
      type: "agent.transcript.final",
      role: row.role,
      text: fragments.map((fragment) => fragment.delta).join(""),
      messageId: row.id,
      modality: "audio",
      status,
      metadata: {
        provider_contract: "gpt-live-1",
        provisional_grouping: true,
        start_ms: row.startMs,
        end_ms: row.endMs,
        fragments,
      },
    };
  }
}

export class OpenAILiveDriver implements RealtimeProviderDriver {
  private readonly events = new EventStream<CanonicalProviderEvent>();
  private connection: RTCPeerConnection | null = null;
  private channel: RTCDataChannel | null = null;
  private stream: MediaStream | null = null;
  private descriptor: ConnectionDescriptor | null = null;
  private bootstrapDescriptor: ConnectionDescriptor | null = null;
  private revision = 1;
  private toolNameMap: Record<string, string> = {};
  private mode: InteractionMode = "voice";
  private model = "gpt-live-1";
  private backendModel = "gpt-5.6-terra";
  private providerSessionId = "";
  private started = false;
  private closed = false;
  private disconnected = true;
  private lastDurationSeconds = -1;
  private transcript = new OpenAILiveTranscriptAssembler();
  private readonly transcriptTimers = new Map<string, ReturnType<typeof setTimeout>>();
  private resolveStarted: (() => void) | null = null;
  private resolveClosed: (() => void) | null = null;
  private startedPromise: Promise<void> = Promise.resolve();
  private closedPromise: Promise<void> = Promise.resolve();
  private readonly audioElements: HTMLAudioElement[] = [];

  constructor(
    private readonly request: typeof fetch = globalThis.fetch.bind(globalThis),
    private readonly peerFactory: () => RTCPeerConnection = () => new RTCPeerConnection(),
    private readonly mediaDevices: MediaDevices | undefined = globalThis.navigator?.mediaDevices,
  ) {}

  async connect(descriptor: ConnectionDescriptor): Promise<void> {
    if (descriptor.connection.transport !== "webrtc" || descriptor.connection.api_variant !== "live") {
      throw new Error("OpenAI Live driver requires a GPT-Live WebRTC descriptor.");
    }

    this.bootstrapDescriptor = descriptor;
    this.mode = "voice";
    this.resetConnectionState(descriptor);
    await this.negotiate(descriptor);
  }

  async disconnect(): Promise<void> {
    await this.closeVoiceTransport();
    this.emitDisconnected();
  }

  async setMode(mode: InteractionMode): Promise<void> {
    this.mode = mode;
    this.stream?.getAudioTracks().forEach((track) => {
      track.enabled = mode === "voice";
    });
    this.audioElements.forEach((audio) => {
      audio.muted = mode === "text";
    });

    if (mode === "text") {
      await this.closeVoiceTransport();
    } else if (!this.channel || this.channel.readyState !== "open" || this.closed) {
      if (!this.bootstrapDescriptor) throw new Error("OpenAI GPT-Live bootstrap descriptor is unavailable.");
      this.resetConnectionState(this.bootstrapDescriptor);
      await this.negotiate(this.bootstrapDescriptor);
    } else {
      this.send({
        type: "session.input_audio.unmute",
        event_id: this.uniqueId("unmute"),
      });
    }

    this.events.emit({ type: "agent.mode.changed", mode });
  }

  async sendText(text: string): Promise<void> {
    if (this.mode === "text") {
      await this.requestText({ type: "message", message: text });
      return;
    }

    this.send({
      type: "response.item.create",
      event_id: this.uniqueId("typed"),
      item: { type: "message", role: "user", content: [{ type: "input_text", text }] },
    });
    this.send(openAIResponseCreateEvent(this.mode, this.uniqueId("response")));
  }

  async sendContext(update: JsonObject): Promise<void> {
    const maximum = Math.max(1, Number(this.descriptor?.connection.context_max_characters ?? 1_600));
    const content = `Current UI context (untrusted reference data): ${JSON.stringify(update)}`.slice(0, maximum);

    this.send({
      type: "session.thinking.append",
      event_id: this.uniqueId("context"),
      delegation_id: null,
      content,
    });
  }

  async submitToolResult(result: ToolResult): Promise<void> {
    this.revision = result.state_revision;

    if (this.mode === "text") {
      await this.requestText({ type: "tool_result", tool_result: result as unknown as JsonObject });
      return;
    }

    this.send({
      type: "response.item.create",
      event_id: this.uniqueId("tool_result"),
      item: {
        type: "function_call_output",
        call_id: result.call_id,
        output: JSON.stringify(result),
      },
    });
    this.send(openAIResponseCreateEvent(this.mode, this.uniqueId("continue")));
  }

  on(listener: (event: CanonicalProviderEvent) => void): () => void {
    return this.events.on(listener);
  }

  private resetConnectionState(descriptor: ConnectionDescriptor): void {
    this.descriptor = descriptor;
    this.revision = Number(descriptor.state.session.revision);
    this.toolNameMap = {};
    this.model = String(descriptor.connection.model ?? "gpt-live-1");
    this.backendModel = String(descriptor.connection.backend_model ?? "gpt-5.6-terra");
    this.providerSessionId = "";
    this.started = false;
    this.closed = false;
    this.disconnected = false;
    this.lastDurationSeconds = -1;
    this.transcript = new OpenAILiveTranscriptAssembler(Number(descriptor.connection.transcript_gap_ms ?? 1_200));
    this.transcriptTimers.forEach((timer) => clearTimeout(timer));
    this.transcriptTimers.clear();
    this.startedPromise = new Promise<void>((resolve) => {
      this.resolveStarted = resolve;
    });
    this.closedPromise = new Promise<void>((resolve) => {
      this.resolveClosed = resolve;
    });
  }

  private async negotiate(descriptor: ConnectionDescriptor): Promise<void> {
    const bootstrapUrl = String(descriptor.connection.bootstrap_url ?? "");

    if (!bootstrapUrl) throw new Error("OpenAI GPT-Live bootstrap URL is missing.");

    const peer = this.peerFactory();
    const channel = peer.createDataChannel("oai-events");
    const opened = new Promise<void>((resolve, reject) => {
      if (channel.readyState === "open") {
        resolve();
        return;
      }

      const timeout = setTimeout(() => reject(new Error("OpenAI GPT-Live data channel timed out.")), 10_000);
      channel.addEventListener("open", () => {
        clearTimeout(timeout);
        resolve();
      }, { once: true });
    });
    this.connection = peer;
    this.channel = channel;
    channel.addEventListener("message", (event) => this.handleMessage(String(event.data)));
    channel.addEventListener("close", () => {
      if (this.channel === channel) this.emitDisconnected();
    });

    if (this.mediaDevices) {
      this.stream = await this.mediaDevices.getUserMedia({ audio: true });
      this.stream.getTracks().forEach((track) => {
        track.enabled = this.mode === "voice";
        peer.addTrack(track, this.stream!);
      });
    }

    peer.addEventListener("track", (event) => {
      if (!globalThis.document) return;
      const audio = document.createElement("audio");
      audio.autoplay = true;
      audio.muted = this.mode === "text";
      audio.srcObject = event.streams[0] ?? new MediaStream([event.track]);
      audio.hidden = true;
      document.body.append(audio);
      this.audioElements.push(audio);
    });

    const offer = await peer.createOffer();
    await peer.setLocalDescription(offer);
    const response = await this.request(bootstrapUrl, {
      method: "POST",
      credentials: "same-origin",
      headers: { Accept: "application/json", "Content-Type": "application/sdp", "X-CSRF-TOKEN": this.csrfToken() },
      body: offer.sdp,
    });
    const connected = await response.json() as ConnectionDescriptor & { error?: JsonObject };

    if (!response.ok) {
      throw new Error(String(connected.error?.message ?? "OpenAI GPT-Live WebRTC bootstrap failed."));
    }

    this.descriptor = connected;
    this.revision = Number(connected.state.session.revision);
    this.toolNameMap = (connected.connection.tool_name_map ?? {}) as Record<string, string>;
    this.model = String(connected.connection.model ?? this.model);
    this.backendModel = String(connected.connection.backend_model ?? this.backendModel);
    this.providerSessionId = String(connected.connection.provider_session_id ?? "");
    await peer.setRemoteDescription({ type: "answer", sdp: String(connected.connection.answer_sdp) });
    await opened;
    await Promise.race([
      this.startedPromise,
      new Promise<void>((_resolve, reject) => setTimeout(() => reject(new Error("OpenAI GPT-Live session.started timed out.")), 10_000)),
    ]);
  }

  private handleMessage(raw: string): void {
    try {
      const event = JSON.parse(raw) as JsonObject;
      const type = String(event.type ?? "");

      if (type === "session.started") {
        const session = asObject(event.session);
        this.providerSessionId = String(session.id ?? this.providerSessionId);
        this.started = true;
        this.resolveStarted?.();
        this.resolveStarted = null;
        if (this.providerSessionId) {
          this.events.emit({ type: "agent.provider.session", providerSessionId: this.providerSessionId });
        }
        this.events.emit({ type: "agent.connected" });
      } else if (type === "session.input_transcript.delta" || type === "session.output_transcript.delta") {
        this.handleTranscriptFragment(type === "session.input_transcript.delta" ? "user" : "agent", event);
      } else if (type === "session.usage.updated" || type === "session.closed") {
        this.handleDuration(event);
        if (type === "session.closed") {
          this.closed = true;
          this.flushTranscripts();
          this.resolveClosed?.();
          this.resolveClosed = null;
        }
      } else if (type === "response.event") {
        this.handleResponseEvent(event);
      } else if (type === "error") {
        const detail = asObject(event.error);
        this.events.emit({ type: "agent.error", error: new Error(String(detail.message ?? "OpenAI GPT-Live error.")) });
      }
    } catch (error) {
      this.events.emit({ type: "agent.error", error: error instanceof Error ? error : new Error("Invalid OpenAI GPT-Live event.") });
    }
  }

  private handleTranscriptFragment(role: TranscriptRole, event: JsonObject): void {
    const update = this.transcript.push(role, event);

    if (!update) return;

    this.events.emit({
      type: "agent.transcript.delta",
      role,
      text: String(event.delta ?? ""),
      messageId: update.rowId,
      modality: "audio",
      metadata: {
        provider_event_id: event.event_id ?? null,
        start_ms: asFiniteNumber(event.start_ms),
        end_ms: asFiniteNumber(event.end_ms),
      },
    });

    if (update.correction) {
      this.events.emit(update.correction);
      return;
    }

    const current = this.transcriptTimers.get(update.rowId);
    if (current) clearTimeout(current);
    const gapMs = Math.max(0, Number(this.descriptor?.connection.transcript_gap_ms ?? 1_200));
    this.transcriptTimers.set(update.rowId, setTimeout(() => {
      this.transcriptTimers.delete(update.rowId);
      const completed = this.transcript.finalize(update.rowId);
      if (completed) this.events.emit(completed);
    }, gapMs));
  }

  private handleDuration(event: JsonObject): void {
    const usage = normalizeOpenAILiveDurationUsage(event, this.providerSessionId, this.model);

    if (!usage) return;

    const seconds = asFiniteNumber(usage.units.duration_seconds, -1);

    if (seconds < this.lastDurationSeconds) return;

    this.lastDurationSeconds = seconds;
    this.events.emit({ type: "agent.usage", usage });
  }

  private handleResponseEvent(envelope: JsonObject): void {
    const event = asObject(envelope.event);
    const type = String(event.type ?? "");
    const delegationId = String(envelope.delegation_id ?? "");

    if (type === "response.output_item.done") {
      this.emitToolCall(asObject(event.item), event, envelope);
    } else if (this.mode === "text" && type === "response.output_text.delta") {
      this.events.emit({
        type: "agent.transcript.delta",
        role: "agent",
        text: String(event.delta ?? ""),
        messageId: String(event.item_id ?? envelope.event_id),
        modality: "text",
        metadata: { delegation_id: delegationId, backend: true },
      });
    } else if (this.mode === "text" && type === "response.output_text.done") {
      this.events.emit({
        type: "agent.transcript.final",
        role: "agent",
        text: String(event.text ?? ""),
        messageId: String(event.item_id ?? envelope.event_id),
        modality: "text",
        metadata: { delegation_id: delegationId, backend: true },
      });
    } else if (type === "response.completed") {
      const response = asObject(event.response);
      const usage = asObject(response.usage);

      if (Object.keys(usage).length === 0) return;

      const responseId = String(response.id ?? event.response_id ?? envelope.event_id ?? this.uniqueId("backend_usage"));
      this.events.emit({
        type: "agent.usage",
        usage: {
          provider: "openai",
          provider_event_id: String(envelope.event_id ?? responseId),
          idempotency_key: `openai-live-response:${responseId}`,
          kind: "response",
          model: String(response.model ?? this.backendModel),
          units: normalizeOpenAIResponseUsage(usage),
          raw: { usage, delegation_id: delegationId, response_id: responseId },
        },
      });
    } else if (type === "response.failed") {
      const response = asObject(event.response);
      const detail = asObject(response.error);
      this.events.emit({ type: "agent.error", error: new Error(String(detail.message ?? "OpenAI delegated response failed.")) });
    }
  }

  private flushTranscripts(): void {
    this.transcriptTimers.forEach((timer) => clearTimeout(timer));
    this.transcriptTimers.clear();
    this.transcript.flushPending().forEach((event) => this.events.emit(event));
  }

  private async closeVoiceTransport(): Promise<void> {
    this.flushTranscripts();

    if (this.channel?.readyState === "open" && this.started && !this.closed) {
      this.send({ type: "session.close", event_id: this.uniqueId("close") });
      const timeoutMs = Math.max(0, Number(this.descriptor?.connection.close_timeout_ms ?? 3_000));
      await Promise.race([
        this.closedPromise,
        new Promise<void>((resolve) => setTimeout(resolve, timeoutMs)),
      ]);
    }

    this.cleanup();
    this.started = false;
  }

  private async requestText(body: JsonObject): Promise<void> {
    const textUrl = String(this.descriptor?.connection.text_url ?? this.bootstrapDescriptor?.connection.text_url ?? "");

    if (!textUrl) throw new Error("OpenAI text continuation URL is missing.");

    const response = await this.request(textUrl, {
      method: "POST",
      credentials: "same-origin",
      headers: { Accept: "application/json", "Content-Type": "application/json", "X-CSRF-TOKEN": this.csrfToken() },
      body: JSON.stringify(body),
    });
    const payload = await response.json() as JsonObject;

    if (!response.ok) {
      const error = asObject(payload.error);
      throw new Error(String(error.message ?? "OpenAI text continuation failed."));
    }

    this.handleTextResponse(asObject(payload.response));
  }

  private handleTextResponse(response: JsonObject): void {
    const responseId = String(response.id ?? this.uniqueId("text_response"));
    let text = "";

    for (const value of Array.isArray(response.output) ? response.output : []) {
      const item = asObject(value);

      if (item.type === "function_call") {
        this.emitToolCall(item, {}, { event_id: responseId });
        continue;
      }

      if (item.type !== "message") continue;

      for (const contentValue of Array.isArray(item.content) ? item.content : []) {
        const content = asObject(contentValue);
        if (content.type === "output_text") text += String(content.text ?? "");
      }
    }

    if (!text) return;

    const metadata = asObject(response._realtime_agent);
    this.events.emit({
      type: "agent.transcript.final",
      role: "agent",
      text,
      messageId: String(metadata.message_id ?? responseId),
      modality: "text",
      metadata: {
        response_id: responseId,
        persisted_server_side: metadata.persisted_server_side === true,
      },
    });
  }

  private emitToolCall(item: JsonObject, event: JsonObject, envelope: JsonObject): void {
    if (item.type !== "function_call") return;

    const callId = String(item.call_id ?? item.id ?? event.item_id ?? envelope.event_id);
    let argumentsValue: JsonObject = {};

    try {
      argumentsValue = typeof item.arguments === "string"
        ? JSON.parse(item.arguments) as JsonObject
        : asObject(item.arguments);
    } catch {
      this.events.emit({ type: "agent.error", error: new Error("OpenAI returned invalid tool arguments.") });
      return;
    }

    this.events.emit({
      type: "agent.tool.call",
      call: {
        id: callId,
        provider_call_id: callId,
        idempotency_key: callId,
        name: this.toolNameMap[String(item.name)] ?? String(item.name),
        arguments: argumentsValue,
        base_revision: this.revision,
      },
    });
  }

  private send(event: JsonObject): void {
    if (!this.channel || this.channel.readyState !== "open" || this.closed) {
      throw new Error("OpenAI GPT-Live data channel is not open.");
    }

    this.channel.send(JSON.stringify(event));
  }

  private cleanup(): void {
    this.transcriptTimers.forEach((timer) => clearTimeout(timer));
    this.transcriptTimers.clear();
    const channel = this.channel;
    const connection = this.connection;
    this.channel = null;
    this.connection = null;
    channel?.close();
    connection?.close();
    this.stream?.getTracks().forEach((track) => track.stop());
    this.stream = null;
    this.audioElements.splice(0).forEach((audio) => audio.remove());
  }

  private emitDisconnected(): void {
    if (this.disconnected) return;

    this.disconnected = true;
    this.events.emit({ type: "agent.disconnected" });
  }

  private uniqueId(prefix: string): string {
    const suffix = globalThis.crypto?.randomUUID?.() ?? `${Date.now()}_${Math.random().toString(16).slice(2)}`;

    return `${prefix}_${suffix}`;
  }

  private csrfToken(): string {
    return globalThis.document?.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content ?? "";
  }
}

// Backward-compatible export name for applications that used the v0.1 adapter.
export { OpenAILiveDriver as OpenAIRealtimeDriver };
