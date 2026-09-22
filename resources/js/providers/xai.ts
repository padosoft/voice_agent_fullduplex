import { EventStream } from "../events.js";
import type {
  CanonicalProviderEvent,
  ConnectionDescriptor,
  InteractionMode,
  JsonObject,
  RealtimeProviderDriver,
  ToolResult,
} from "../types.js";
import { BrowserRealtimeAudioBridge, type RealtimeAudioBridge } from "./audio.js";

type WebSocketFactory = (url: string, protocols?: string | string[]) => WebSocket;

const asObject = (value: unknown): JsonObject => value !== null && typeof value === "object" ? value as JsonObject : {};
const asArray = (value: unknown): unknown[] => Array.isArray(value) ? value : [];

const pcmSeconds = (base64Pcm: string, sampleRate: number): number => {
  try {
    return Math.floor(globalThis.atob(base64Pcm).length / 2) / sampleRate;
  } catch {
    return 0;
  }
};

/** Tracks local, billable audio frames for xAI because the realtime protocol
 * bills both input and output audio by duration. */
export function xaiAudioUsage(
  providerEventId: string,
  model: string,
  direction: "input" | "output",
  seconds: number,
): { provider: "xai"; provider_event_id: string; idempotency_key: string; kind: "duration"; model: string; units: Record<string, number>; raw: JsonObject } {
  return {
    provider: "xai",
    provider_event_id: providerEventId,
    idempotency_key: `xai-voice-${providerEventId}`,
    kind: "duration",
    model,
    units: { [`${direction}_audio_seconds`]: Math.max(0, seconds) },
    raw: { source: "browser_pcm_frame_meter", direction },
  };
}

export class XaiVoiceDriver implements RealtimeProviderDriver {
  private readonly events = new EventStream<CanonicalProviderEvent>();
  private socket: WebSocket | null = null;
  private descriptor: ConnectionDescriptor | null = null;
  private connected: ConnectionDescriptor | null = null;
  private revision = 1;
  private mode: InteractionMode = "voice";
  private model = "grok-voice-latest";
  private toolNameMap: Record<string, string> = {};
  private readonly inputTranscripts = new Map<string, string>();
  private manualClose = false;
  private reconnecting = false;
  private eventSequence = 0;
  private resolveConfigured: (() => void) | null = null;
  private meterKey = "";
  private inputAudioSeconds = 0;
  private outputAudioSeconds = 0;
  private emittedInputAudioSeconds = 0;
  private emittedOutputAudioSeconds = 0;

  constructor(
    private readonly request: typeof fetch = globalThis.fetch.bind(globalThis),
    private readonly socketFactory: WebSocketFactory = (url, protocols) => new WebSocket(url, protocols),
    private readonly audio: RealtimeAudioBridge = new BrowserRealtimeAudioBridge(),
  ) {}

  async connect(descriptor: ConnectionDescriptor): Promise<void> {
    this.descriptor = descriptor;
    this.manualClose = false;
    this.revision = Number(descriptor.state.session.revision);
    await this.bootstrapAndOpen();
  }

  async disconnect(): Promise<void> {
    this.manualClose = true;
    this.flushAudioUsage(true);
    this.audio.stop();
    this.socket?.close();
    this.socket = null;
  }

  async setMode(mode: InteractionMode): Promise<void> {
    this.mode = mode;
    this.audio.setMuted(mode === "text");
    if (mode === "text") this.audio.flush();
    this.events.emit({ type: "agent.mode.changed", mode });
  }

  async sendText(text: string): Promise<void> {
    this.send({
      type: "conversation.item.create",
      item: { type: "message", role: "user", content: [{ type: "input_text", text }] },
    });
    this.recordTextInputUsage("conversation.item.create");
    this.send({ type: "response.create" });
  }

  async sendContext(update: JsonObject): Promise<void> {
    const maximum = Math.max(1, Number(this.connected?.connection.context_max_characters ?? 1_600));
    const text = `Current UI context (untrusted reference data): ${JSON.stringify(update)}`.slice(0, maximum);
    this.send({
      type: "conversation.item.create",
      item: { type: "message", role: "user", content: [{ type: "input_text", text }] },
    });
    this.recordTextInputUsage("contextual_update");
  }

  async submitToolResult(result: ToolResult): Promise<void> {
    this.revision = result.state_revision;
    this.send({
      type: "conversation.item.create",
      item: { type: "function_call_output", call_id: result.call_id, output: JSON.stringify(result) },
    });
    this.send({ type: "response.create" });
  }

  on(listener: (event: CanonicalProviderEvent) => void): () => void {
    return this.events.on(listener);
  }

  private async bootstrapAndOpen(): Promise<void> {
    if (!this.descriptor) throw new Error("xAI bootstrap descriptor is unavailable.");
    const bootstrapUrl = String(this.descriptor.connection.bootstrap_url ?? "");

    if (!bootstrapUrl) throw new Error("xAI bootstrap URL is missing.");

    const response = await this.request(bootstrapUrl, {
      method: "POST",
      credentials: "same-origin",
      headers: { Accept: "application/json", "Content-Type": "application/json", "X-CSRF-TOKEN": this.csrfToken() },
      body: "{}",
    });
    const connected = await response.json() as ConnectionDescriptor & { error?: JsonObject };

    if (!response.ok) throw new Error(String(connected.error?.message ?? "xAI Voice bootstrap failed."));

    this.connected = connected;
    this.model = String(connected.connection.model ?? this.model);
    this.toolNameMap = (connected.connection.tool_name_map ?? {}) as Record<string, string>;
    const endpoint = new URL(String(connected.connection.endpoint));
    endpoint.searchParams.set("model", this.model);
    const socket = this.socketFactory(endpoint.toString(), [`xai-client-secret.${String(connected.connection.client_secret)}`]);
    this.socket = socket;
    await new Promise<void>((resolve, reject) => {
      const timeout = setTimeout(() => reject(new Error("xAI Voice WebSocket timed out.")), 10_000);
      socket.addEventListener("open", () => {
        clearTimeout(timeout);
        resolve();
      }, { once: true });
      socket.addEventListener("error", () => {
        clearTimeout(timeout);
        reject(new Error("xAI Voice WebSocket error."));
      }, { once: true });
    });
    socket.addEventListener("message", (event) => this.handleMessage(String(event.data)));
    socket.addEventListener("close", () => this.handleClose());
    socket.addEventListener("error", () => this.events.emit({ type: "agent.error", error: new Error("xAI Voice WebSocket error.") }));
    this.meterKey = `xai-meter-${Date.now()}-${++this.eventSequence}`;
    this.inputAudioSeconds = 0;
    this.outputAudioSeconds = 0;
    this.emittedInputAudioSeconds = 0;
    this.emittedOutputAudioSeconds = 0;
    const configured = new Promise<void>((resolve) => { this.resolveConfigured = resolve; });
    this.send({ type: "session.update", session: asObject(connected.connection.session) });
    await Promise.race([
      configured,
      new Promise<void>((_resolve, reject) => setTimeout(() => reject(new Error("xAI Voice session configuration timed out.")), 10_000)),
    ]);
    this.seedHistory(asArray(connected.connection.history));
    await this.audio.start((chunk, seconds) => {
      this.send({ type: "input_audio_buffer.append", audio: chunk });
      this.inputAudioSeconds += seconds;
      this.flushAudioUsage(false);
    });
    this.audio.setMuted(this.mode === "text");
    this.events.emit({ type: "agent.connected" });
  }

  private handleMessage(raw: string): void {
    try {
      const event = JSON.parse(raw) as JsonObject;
      const type = String(event.type ?? "");

      if (type === "session.updated") {
        this.resolveConfigured?.();
        this.resolveConfigured = null;
      } else if (type === "session.created" || type === "conversation.created") {
        const session = asObject(event.session ?? event.conversation);
        const id = String(session.id ?? event.session_id ?? event.conversation_id ?? "");
        if (id) this.events.emit({ type: "agent.provider.session", providerSessionId: id });
      } else if (type === "response.output_audio.delta") {
        const delta = String(event.delta ?? asObject(event.audio).data ?? "");
        this.audio.play(delta, 24_000);
        this.events.emit({ type: "agent.audio.delta", audio: delta });
        this.outputAudioSeconds += pcmSeconds(delta, 24_000);
        this.flushAudioUsage(false);
      } else if (type === "response.output_audio_transcript.delta") {
        this.events.emit({ type: "agent.transcript.delta", role: "agent", text: String(event.delta ?? ""), messageId: String(event.item_id ?? "xai-agent"), modality: "audio" });
      } else if (type === "response.output_audio_transcript.done") {
        this.events.emit({ type: "agent.transcript.final", role: "agent", text: String(event.transcript ?? event.text ?? ""), messageId: String(event.item_id ?? `xai-agent-${++this.eventSequence}`), modality: this.mode === "text" ? "text" : "audio" });
      } else if (type === "conversation.item.input_audio_transcription.updated") {
        this.handleInputTranscript(event, false);
      } else if (type === "conversation.item.input_audio_transcription.completed") {
        this.handleInputTranscript(event, true);
      } else if (type === "response.output_item.done") {
        this.handleFunctionCall(asObject(event.item));
      } else if (type === "input_audio_buffer.speech_started") {
        this.audio.flush();
      } else if (type === "error") {
        this.events.emit({ type: "agent.error", error: new Error(String(asObject(event.error).message ?? "xAI Voice error.")) });
      }
    } catch (error) {
      this.events.emit({ type: "agent.error", error: error instanceof Error ? error : new Error("Invalid xAI Voice event.") });
    }
  }

  private handleInputTranscript(event: JsonObject, complete: boolean): void {
    const id = String(event.item_id ?? event.id ?? `xai-user-${++this.eventSequence}`);
    const text = String(event.transcript ?? event.text ?? "");
    const previous = this.inputTranscripts.get(id);

    if (!text) return;

    this.inputTranscripts.set(id, text);
    this.events.emit({ type: "agent.transcript.delta", role: "user", text, messageId: id, modality: "audio" });
    if (complete || previous !== undefined) {
      this.events.emit({ type: "agent.transcript.final", role: "user", text, messageId: id, modality: "audio", status: previous === undefined ? "completed" : "corrected" });
    }
  }

  private handleFunctionCall(item: JsonObject): void {
    if (item.type !== "function_call") return;

    const id = String(item.call_id ?? item.id ?? `xai-call-${++this.eventSequence}`);
    const providerName = String(item.name ?? "");
    let argumentsValue: JsonObject = {};

    try { argumentsValue = asObject(JSON.parse(String(item.arguments ?? "{}"))); } catch { /* Laravel validates malformed arguments. */ }

    this.events.emit({
      type: "agent.tool.call",
      call: {
        id,
        provider_call_id: id,
        idempotency_key: id,
        name: this.toolNameMap[providerName] ?? providerName,
        arguments: argumentsValue,
        base_revision: this.revision,
      },
    });
  }

  private seedHistory(history: unknown[]): void {
    history.forEach((entry) => {
      const item = asObject(entry);
      const text = String(item.text ?? "");

      if (text) {
        this.send({ type: "conversation.item.create", item: { type: "message", role: item.role === "assistant" ? "assistant" : "user", content: [{ type: "input_text", text }] } });
        this.recordTextInputUsage("rehydrated_history");
      }
    });
  }

  private handleClose(): void {
    this.flushAudioUsage(true);
    this.events.emit({ type: "agent.disconnected" });
    if (!this.manualClose) void this.reconnect();
  }

  private async reconnect(): Promise<void> {
    if (this.reconnecting || this.manualClose) return;

    this.reconnecting = true;
    this.audio.stop();

    try {
      await this.bootstrapAndOpen();
    } catch (error) {
      this.events.emit({ type: "agent.error", error: error instanceof Error ? error : new Error("xAI Voice reconnect failed.") });
    } finally {
      this.reconnecting = false;
    }
  }

  private flushAudioUsage(force: boolean): void {
    if (!this.meterKey) return;

    if ((force && this.inputAudioSeconds > this.emittedInputAudioSeconds) || this.inputAudioSeconds - this.emittedInputAudioSeconds >= 1) {
      this.emittedInputAudioSeconds = this.inputAudioSeconds;
      const usage = xaiAudioUsage(`${this.meterKey}:input`, this.model, "input", this.inputAudioSeconds);
      usage.raw = { ...usage.raw, cumulative_snapshot: true };
      this.events.emit({ type: "agent.usage", usage });
    }

    if ((force && this.outputAudioSeconds > this.emittedOutputAudioSeconds) || this.outputAudioSeconds - this.emittedOutputAudioSeconds >= 1) {
      this.emittedOutputAudioSeconds = this.outputAudioSeconds;
      const usage = xaiAudioUsage(`${this.meterKey}:output`, this.model, "output", this.outputAudioSeconds);
      usage.raw = { ...usage.raw, cumulative_snapshot: true };
      this.events.emit({ type: "agent.usage", usage });
    }
  }

  private send(event: JsonObject): void {
    if (!this.socket || this.socket.readyState !== WebSocket.OPEN) throw new Error("xAI Voice WebSocket is not open.");

    this.socket.send(JSON.stringify(event));
  }

  private recordTextInputUsage(source: string): void {
    const sequence = ++this.eventSequence;
    this.events.emit({
      type: "agent.usage",
      usage: {
        provider: "xai",
        provider_event_id: `xai-text-${sequence}`,
        idempotency_key: `xai-text-${sequence}`,
        kind: "conversation",
        model: this.model,
        units: { text_input_messages: 1 },
        raw: { source },
      },
    });
  }

  private csrfToken(): string {
    return globalThis.document?.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content ?? "";
  }
}
