import type { CanonicalProviderEvent, ConnectionDescriptor, JsonObject, RealtimeProviderDriver, ToolResult } from "../types.js";
export declare class FakeRealtimeDriver implements RealtimeProviderDriver {
    private readonly events;
    private connected;
    readonly context: JsonObject[];
    readonly toolResults: ToolResult[];
    connect(descriptor: ConnectionDescriptor): Promise<void>;
    disconnect(): Promise<void>;
    sendText(text: string): Promise<void>;
    sendContext(update: JsonObject): Promise<void>;
    submitToolResult(result: ToolResult): Promise<void>;
    on(listener: (event: CanonicalProviderEvent) => void): () => void;
    emit(event: CanonicalProviderEvent): void;
    play(events: readonly CanonicalProviderEvent[], delayMs?: number): Promise<void>;
    private assertConnected;
}
