import { EventStream } from "./events.js";
import type {
  AgentState,
  ConfirmationHandler,
  CanonicalProviderEvent,
  ConnectionDescriptor,
  ControlTransport,
  JsonObject,
  RealtimeAgentClientEvent,
  RealtimeProviderDriver,
  SessionAudit,
  InteractionMode,
  ToolResult,
  UiCommand,
} from "./types.js";
import { SurfaceRegistry } from "./surface/registry.js";
import { UiCommandExecutor } from "./surface/command-executor.js";
import { semanticDiff } from "./surface/diff.js";

export interface RealtimeAgentClientOptions {
  confirmation?: ConfirmationHandler;
  surfaceDebounceMs?: number;
}

export class RealtimeAgentClient {
  readonly surface: SurfaceRegistry;
  private readonly events = new EventStream<RealtimeAgentClientEvent>();
  private unsubscribeProvider: (() => void) | null = null;
  private unsubscribeSurface: (() => void) | null = null;
  private state: AgentState | null = null;
  private sessionId: string | null = null;
  private executor: UiCommandExecutor | null = null;
  private surfaceTimer: ReturnType<typeof setTimeout> | null = null;
  private lastSurface: JsonObject | undefined;
  private providerName: string | null = null;
  private interactionMode: InteractionMode = "voice";
  private auditQueue: Promise<void> = Promise.resolve();

  constructor(
    readonly surfaces: SurfaceRegistry,
    private readonly provider: RealtimeProviderDriver,
    private readonly control: ControlTransport,
    private readonly options: RealtimeAgentClientOptions = {},
  ) {
    this.surface = surfaces;
  }

  async connect(descriptor: ConnectionDescriptor): Promise<void> {
    this.sessionId = descriptor.session_id;
    this.providerName = descriptor.provider;
    this.state = descriptor.state;
    this.auditQueue = Promise.resolve();
    this.executor = new UiCommandExecutor(descriptor.session_id, this.surfaces);
    this.unsubscribeProvider = this.provider.on((event) => {
      this.events.emit(event);

      if (event.type === "agent.tool.call") void this.handleToolCall(event.call);
      if (event.type === "agent.transcript.final" || event.type === "agent.usage") {
        this.auditQueue = this.auditQueue.then(() => this.persistProviderEvent(event));
      }
    });
    this.unsubscribeSurface = this.surfaces.onChange(() => this.scheduleSurfaceSync());
    await this.provider.connect(descriptor);

    if (this.surfaces.currentId()) await this.syncSurface();
  }

  async disconnect(): Promise<void> {
    if (this.surfaceTimer) clearTimeout(this.surfaceTimer);
    this.surfaceTimer = null;
    await this.provider.disconnect();
    await this.auditQueue;
    this.unsubscribeProvider?.();
    this.unsubscribeSurface?.();
    this.unsubscribeProvider = null;
    this.unsubscribeSurface = null;
  }

  async sendText(text: string): Promise<void> {
    const content = text.trim();

    if (!content) throw new Error("A text message cannot be empty.");

    const messageId = `client_${this.uniqueId()}`;
    await this.control.recordMessage({
      provider: this.providerId(),
      provider_event_id: messageId,
      idempotency_key: messageId,
      role: "user",
      direction: "input",
      modality: "text",
      status: "completed",
      content,
      metadata: { interaction_mode: this.interactionMode },
    });
    this.events.emit({
      type: "agent.transcript.final",
      role: "user",
      text: content,
      messageId,
      modality: "text",
    });
    await this.provider.sendText(content);
  }

  async switchToText(): Promise<void> {
    await this.provider.setMode("text");
    await this.auditQueue;
    this.interactionMode = "text";
  }

  async switchToVoice(): Promise<void> {
    await this.provider.setMode("voice");
    await this.auditQueue;
    this.interactionMode = "voice";
  }

  async audit(): Promise<SessionAudit> {
    return this.control.fetchAudit();
  }

  async refreshState(): Promise<AgentState> {
    return this.setState(await this.control.refreshState());
  }

  async finish(): Promise<AgentState> {
    return this.setState(await this.control.finish(this.revision()));
  }

  on(listener: (event: RealtimeAgentClientEvent) => void): () => void {
    return this.events.on(listener);
  }

  private async handleToolCall(call: Parameters<ControlTransport["executeTool"]>[0]): Promise<void> {
    try {
      let result = await this.control.executeTool({ ...call, base_revision: this.revision() });
      this.setRevision(result.state_revision);

      if (result.status === "confirmation_required") {
        const confirmation = (result.output?.confirmation ?? {}) as JsonObject;
        this.events.emit({ type: "confirmation.requested", confirmation });
        const accepted = await this.confirm(confirmation);
        result = await this.control.resolveConfirmation(String(confirmation.id), accepted);
        this.setRevision(result.state_revision);
      }

      result = await this.executeUiCommand(result);
      await this.provider.submitToolResult(result);
    } catch (error) {
      this.events.emit({ type: "agent.error", error: error instanceof Error ? error : new Error("Tool execution failed.") });
    }
  }

  private async persistProviderEvent(event: CanonicalProviderEvent): Promise<void> {
    try {
      if (event.type === "agent.usage") {
        await this.control.recordUsage(event.usage);
        return;
      }

      if (event.type === "agent.transcript.final") {
        if (!event.text.trim()) return;
        if (event.metadata?.persisted_server_side === true) return;

        await this.control.recordMessage({
          provider: this.providerId(),
          provider_event_id: event.messageId,
          idempotency_key: event.messageId,
          role: event.role === "agent" ? "assistant" : "user",
          direction: event.role === "agent" ? "output" : "input",
          modality: event.modality,
          status: event.status ?? "completed",
          content: event.text,
          metadata: { interaction_mode: this.interactionMode, ...(event.metadata ?? {}) },
        });
      }
    } catch (error) {
      this.events.emit({
        type: "agent.error",
        error: error instanceof Error ? error : new Error("Provider audit event could not be persisted."),
      });
    }
  }

  private async executeUiCommand(result: ToolResult): Promise<ToolResult> {
    const command = result.output?.command as UiCommand | undefined;

    if (!command || !this.executor) return result;

    const uiResult = await this.executor.execute(command);
    const state = await this.control.completeUiCommand(command, result.state_revision, uiResult);
    this.setState(state);

    return {
      call_id: result.call_id,
      status: uiResult.status,
      output: JSON.parse(JSON.stringify({ ui_result: uiResult })) as JsonObject,
      error: uiResult.error as JsonObject | undefined,
      state_revision: Number(state.session.revision),
    };
  }

  private confirm(confirmation: JsonObject): Promise<boolean> | boolean {
    if (this.options.confirmation) return this.options.confirmation(confirmation);

    return typeof globalThis.confirm === "function"
      ? globalThis.confirm(`Allow ${String((confirmation.call as JsonObject | undefined)?.name ?? "this action")}?`)
      : false;
  }

  private scheduleSurfaceSync(): void {
    if (!this.state) return;
    if (this.surfaceTimer) clearTimeout(this.surfaceTimer);
    this.surfaceTimer = setTimeout(
      () => void this.syncSurface(),
      this.options.surfaceDebounceMs ?? 150,
    );
  }

  private async syncSurface(): Promise<void> {
    const snapshot = this.surfaces.snapshot();

    if (semanticDiff(this.lastSurface, snapshot).length === 0) return;

    const state = await this.control.syncSurface(this.revision(), snapshot);
    this.lastSurface = snapshot;
    this.setState(state);
    await this.provider.sendContext({ ui: snapshot });
    this.events.emit({ type: "surface.synced", surface: snapshot });
  }

  private revision(): number {
    if (!this.state) throw new Error("Realtime Agent client is not connected.");

    return Number(this.state.session.revision);
  }

  private providerId(): string {
    if (!this.providerName) throw new Error("Realtime Agent client is not connected.");

    return this.providerName;
  }

  private uniqueId(): string {
    return globalThis.crypto?.randomUUID?.() ?? `${Date.now()}_${Math.random().toString(16).slice(2)}`;
  }

  private setRevision(revision: number): void {
    if (this.state) this.state.session.revision = revision;
  }

  private setState(state: AgentState): AgentState {
    this.state = state;
    this.events.emit({ type: "state.updated", state });

    return state;
  }
}

export * from "./types.js";
export { EventStream } from "./events.js";
export { LaravelControlTransport, RealtimeControlError } from "./control.js";
export { SurfaceRegistry } from "./surface/registry.js";
export { DomSurfaceAdapter } from "./surface/dom-adapter.js";
export { semanticDiff } from "./surface/diff.js";
export { UiCommandExecutor } from "./surface/command-executor.js";
export { FakeRealtimeDriver } from "./providers/fake.js";
export { ElevenLabsRealtimeDriver } from "./providers/elevenlabs.js";
export { OpenAILiveDriver, OpenAIRealtimeDriver } from "./providers/openai.js";
