import { EventStream } from "./events.js";
import { UiCommandExecutor } from "./surface/command-executor.js";
import { semanticDiff } from "./surface/diff.js";
export class RealtimeAgentClient {
    surfaces;
    provider;
    control;
    options;
    surface;
    events = new EventStream();
    unsubscribeProvider = null;
    unsubscribeSurface = null;
    state = null;
    sessionId = null;
    executor = null;
    surfaceTimer = null;
    lastSurface;
    providerName = null;
    interactionMode = "voice";
    constructor(surfaces, provider, control, options = {}) {
        this.surfaces = surfaces;
        this.provider = provider;
        this.control = control;
        this.options = options;
        this.surface = surfaces;
    }
    async connect(descriptor) {
        this.sessionId = descriptor.session_id;
        this.providerName = descriptor.provider;
        this.state = descriptor.state;
        this.executor = new UiCommandExecutor(descriptor.session_id, this.surfaces);
        this.unsubscribeProvider = this.provider.on((event) => {
            this.events.emit(event);
            if (event.type === "agent.tool.call")
                void this.handleToolCall(event.call);
            if (event.type === "agent.transcript.final" || event.type === "agent.usage") {
                void this.persistProviderEvent(event);
            }
        });
        this.unsubscribeSurface = this.surfaces.onChange(() => this.scheduleSurfaceSync());
        await this.provider.connect(descriptor);
        if (this.surfaces.currentId())
            await this.syncSurface();
    }
    async disconnect() {
        if (this.surfaceTimer)
            clearTimeout(this.surfaceTimer);
        this.surfaceTimer = null;
        await this.provider.disconnect();
        this.unsubscribeProvider?.();
        this.unsubscribeSurface?.();
        this.unsubscribeProvider = null;
        this.unsubscribeSurface = null;
    }
    async sendText(text) {
        const content = text.trim();
        if (!content)
            throw new Error("A text message cannot be empty.");
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
    async switchToText() {
        this.interactionMode = "text";
        await this.provider.setMode("text");
    }
    async switchToVoice() {
        this.interactionMode = "voice";
        await this.provider.setMode("voice");
    }
    async audit() {
        return this.control.fetchAudit();
    }
    async refreshState() {
        return this.setState(await this.control.refreshState());
    }
    async finish() {
        return this.setState(await this.control.finish(this.revision()));
    }
    on(listener) {
        return this.events.on(listener);
    }
    async handleToolCall(call) {
        try {
            let result = await this.control.executeTool({ ...call, base_revision: this.revision() });
            this.setRevision(result.state_revision);
            if (result.status === "confirmation_required") {
                const confirmation = (result.output?.confirmation ?? {});
                this.events.emit({ type: "confirmation.requested", confirmation });
                const accepted = await this.confirm(confirmation);
                result = await this.control.resolveConfirmation(String(confirmation.id), accepted);
                this.setRevision(result.state_revision);
            }
            result = await this.executeUiCommand(result);
            await this.provider.submitToolResult(result);
        }
        catch (error) {
            this.events.emit({ type: "agent.error", error: error instanceof Error ? error : new Error("Tool execution failed.") });
        }
    }
    async persistProviderEvent(event) {
        try {
            if (event.type === "agent.usage") {
                await this.control.recordUsage(event.usage);
                return;
            }
            if (event.type === "agent.transcript.final") {
                if (!event.text.trim())
                    return;
                await this.control.recordMessage({
                    provider: this.providerId(),
                    provider_event_id: event.messageId,
                    idempotency_key: event.messageId,
                    role: event.role === "agent" ? "assistant" : "user",
                    direction: event.role === "agent" ? "output" : "input",
                    modality: event.modality,
                    status: event.status ?? "completed",
                    content: event.text,
                    metadata: { interaction_mode: this.interactionMode },
                });
            }
        }
        catch (error) {
            this.events.emit({
                type: "agent.error",
                error: error instanceof Error ? error : new Error("Provider audit event could not be persisted."),
            });
        }
    }
    async executeUiCommand(result) {
        const command = result.output?.command;
        if (!command || !this.executor)
            return result;
        const uiResult = await this.executor.execute(command);
        const state = await this.control.completeUiCommand(command, result.state_revision, uiResult);
        this.setState(state);
        return {
            call_id: result.call_id,
            status: uiResult.status,
            output: JSON.parse(JSON.stringify({ ui_result: uiResult })),
            error: uiResult.error,
            state_revision: Number(state.session.revision),
        };
    }
    confirm(confirmation) {
        if (this.options.confirmation)
            return this.options.confirmation(confirmation);
        return typeof globalThis.confirm === "function"
            ? globalThis.confirm(`Allow ${String(confirmation.call?.name ?? "this action")}?`)
            : false;
    }
    scheduleSurfaceSync() {
        if (!this.state)
            return;
        if (this.surfaceTimer)
            clearTimeout(this.surfaceTimer);
        this.surfaceTimer = setTimeout(() => void this.syncSurface(), this.options.surfaceDebounceMs ?? 150);
    }
    async syncSurface() {
        const snapshot = this.surfaces.snapshot();
        if (semanticDiff(this.lastSurface, snapshot).length === 0)
            return;
        const state = await this.control.syncSurface(this.revision(), snapshot);
        this.lastSurface = snapshot;
        this.setState(state);
        await this.provider.sendContext({ ui: snapshot });
        this.events.emit({ type: "surface.synced", surface: snapshot });
    }
    revision() {
        if (!this.state)
            throw new Error("Realtime Agent client is not connected.");
        return Number(this.state.session.revision);
    }
    providerId() {
        if (!this.providerName)
            throw new Error("Realtime Agent client is not connected.");
        return this.providerName;
    }
    uniqueId() {
        return globalThis.crypto?.randomUUID?.() ?? `${Date.now()}_${Math.random().toString(16).slice(2)}`;
    }
    setRevision(revision) {
        if (this.state)
            this.state.session.revision = revision;
    }
    setState(state) {
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
export { OpenAIRealtimeDriver } from "./providers/openai.js";
export { ElevenLabsRealtimeDriver } from "./providers/elevenlabs.js";
