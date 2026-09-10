import type { CanonicalProviderEvent, ConnectionDescriptor, JsonObject, RealtimeProviderDriver, ToolResult } from "../types.js";
type WebSocketFactory = (url: string) => WebSocket;
export declare class ElevenLabsRealtimeDriver implements RealtimeProviderDriver {
    private readonly request;
    private readonly socketFactory;
    private readonly events;
    private socket;
    private revision;
    private toolNameMap;
    constructor(request?: typeof fetch, socketFactory?: WebSocketFactory);
    connect(descriptor: ConnectionDescriptor): Promise<void>;
    disconnect(): Promise<void>;
    sendText(text: string): Promise<void>;
    sendContext(update: JsonObject): Promise<void>;
    submitToolResult(result: ToolResult): Promise<void>;
    on(listener: (event: CanonicalProviderEvent) => void): () => void;
    private handleMessage;
    private send;
    private csrfToken;
}
export {};
