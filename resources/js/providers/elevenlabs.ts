import { EventStream } from "../events.js";
import type {
  CanonicalProviderEvent,
  ConnectionDescriptor,
  JsonObject,
  RealtimeProviderDriver,
  ToolResult,
} from "../types.js";

type WebSocketFactory = (url: string) => WebSocket;

export class ElevenLabsRealtimeDriver implements RealtimeProviderDriver {
  private readonly events = new EventStream<CanonicalProviderEvent>();
  private socket: WebSocket | null = null;
  private revision = 1;
  private toolNameMap: Record<string, string> = {};

  constructor(
    private readonly request: typeof fetch = globalThis.fetch.bind(globalThis),
    private readonly socketFactory: WebSocketFactory = (url) => new WebSocket(url),
  ) {}

  async connect(descriptor: ConnectionDescriptor): Promise<void> {
    if (descriptor.connection.transport !== "websocket") {
      throw new Error("ElevenLabs driver requires a WebSocket descriptor.");
    }

    this.revision = Number(descriptor.state.session.revision);
    const bootstrapUrl = String(descriptor.connection.bootstrap_url ?? "");
    const response = await this.request(bootstrapUrl, {
      method: "POST",
      credentials: "same-origin",
      headers: { Accept: "application/json", "Content-Type": "application/json", "X-CSRF-TOKEN": this.csrfToken() },
      body: "{}",
    });
    const connected = await response.json() as ConnectionDescriptor & { error?: JsonObject };

    if (!response.ok) throw new Error(String(connected.error?.message ?? "ElevenLabs bootstrap failed."));

    this.toolNameMap = (connected.connection.tool_name_map ?? {}) as Record<string, string>;
    const socket = this.socketFactory(String(connected.connection.signed_url));
    this.socket = socket;
    const opened = new Promise<void>((resolve, reject) => {
      const timeout = setTimeout(() => reject(new Error("ElevenLabs WebSocket timed out.")), 10_000);
      socket.addEventListener("open", () => {
        clearTimeout(timeout);
        resolve();
      }, { once: true });
    });
    socket.addEventListener("open", () => {
      this.send({
        type: "conversation_initiation_client_data",
        dynamic_variables: connected.connection.dynamic_variables as JsonObject,
        conversation_config_override: connected.connection.overrides as JsonObject,
      });
      this.events.emit({ type: "agent.connected" });
    });
    socket.addEventListener("message", (event) => this.handleMessage(String(event.data)));
    socket.addEventListener("close", () => this.events.emit({ type: "agent.disconnected" }));
    socket.addEventListener("error", () => this.events.emit({ type: "agent.error", error: new Error("ElevenLabs WebSocket error.") }));
    await opened;
  }

  async disconnect(): Promise<void> {
    this.socket?.close();
    this.socket = null;
  }

  async sendText(text: string): Promise<void> {
    this.send({ type: "user_message", text });
  }

  async sendContext(update: JsonObject): Promise<void> {
    this.send({ type: "contextual_update", text: JSON.stringify(update) });
  }

  async submitToolResult(result: ToolResult): Promise<void> {
    this.revision = result.state_revision;
    this.send({
      type: "client_tool_result",
      tool_call_id: result.call_id,
      result: JSON.stringify(result),
      is_error: result.status === "failed" || result.status === "rejected",
    });
  }

  on(listener: (event: CanonicalProviderEvent) => void): () => void {
    return this.events.on(listener);
  }

  private handleMessage(raw: string): void {
    try {
      const event = JSON.parse(raw) as JsonObject;
      const type = String(event.type ?? "");

      if (type === "client_tool_call") {
        const providerName = String(event.tool_name ?? event.name);
        this.events.emit({
          type: "agent.tool.call",
          call: {
            id: String(event.tool_call_id),
            provider_call_id: String(event.tool_call_id),
            idempotency_key: String(event.tool_call_id),
            name: this.toolNameMap[providerName] ?? providerName,
            arguments: (event.parameters ?? {}) as JsonObject,
            base_revision: this.revision,
          },
        });
      } else if (type === "agent_response" || type === "agent_response_correction") {
        this.events.emit({ type: "agent.transcript.delta", role: "agent", text: String(event.agent_response_event ?? event.text ?? "") });
      } else if (type === "user_transcript") {
        this.events.emit({ type: "agent.transcript.delta", role: "user", text: String(event.user_transcription_event ?? event.text ?? "") });
      } else if (type === "audio") {
        const audio = event.audio_event as JsonObject | undefined;
        this.events.emit({ type: "agent.audio.delta", audio: String(audio?.audio_base_64 ?? event.audio ?? "") });
      } else if (type === "ping") {
        this.send({ type: "pong", event_id: event.ping_event_id });
      }
    } catch (error) {
      this.events.emit({ type: "agent.error", error: error instanceof Error ? error : new Error("Invalid ElevenLabs event.") });
    }
  }

  private send(event: JsonObject): void {
    if (!this.socket || this.socket.readyState !== 1) {
      throw new Error("ElevenLabs WebSocket is not open.");
    }

    this.socket.send(JSON.stringify(event));
  }

  private csrfToken(): string {
    return globalThis.document?.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content ?? "";
  }
}
