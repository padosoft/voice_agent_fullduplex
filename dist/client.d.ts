import type { AgentState, ConfirmationHandler, ConnectionDescriptor, ControlTransport, RealtimeAgentClientEvent, RealtimeProviderDriver } from "./types.js";
import { SurfaceRegistry } from "./surface/registry.js";
export interface RealtimeAgentClientOptions {
    confirmation?: ConfirmationHandler;
    surfaceDebounceMs?: number;
}
export declare class RealtimeAgentClient {
    readonly surfaces: SurfaceRegistry;
    private readonly provider;
    private readonly control;
    private readonly options;
    readonly surface: SurfaceRegistry;
    private readonly events;
    private unsubscribeProvider;
    private unsubscribeSurface;
    private state;
    private sessionId;
    private executor;
    private surfaceTimer;
    private lastSurface;
    constructor(surfaces: SurfaceRegistry, provider: RealtimeProviderDriver, control: ControlTransport, options?: RealtimeAgentClientOptions);
    connect(descriptor: ConnectionDescriptor): Promise<void>;
    disconnect(): Promise<void>;
    sendText(text: string): Promise<void>;
    refreshState(): Promise<AgentState>;
    finish(): Promise<AgentState>;
    on(listener: (event: RealtimeAgentClientEvent) => void): () => void;
    private handleToolCall;
    private executeUiCommand;
    private confirm;
    private scheduleSurfaceSync;
    private syncSurface;
    private revision;
    private setRevision;
    private setState;
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
