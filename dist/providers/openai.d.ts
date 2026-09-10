import type { CanonicalProviderEvent, ConnectionDescriptor, JsonObject, RealtimeProviderDriver, ToolResult } from "../types.js";
export declare class OpenAIRealtimeDriver implements RealtimeProviderDriver {
    private readonly request;
    private readonly peerFactory;
    private readonly mediaDevices;
    private readonly events;
    private connection;
    private channel;
    private stream;
    private descriptor;
    private revision;
    private reconnectTimer;
    private toolNameMap;
    constructor(request?: typeof fetch, peerFactory?: () => RTCPeerConnection, mediaDevices?: MediaDevices | undefined);
    connect(descriptor: ConnectionDescriptor): Promise<void>;
    disconnect(): Promise<void>;
    sendText(text: string): Promise<void>;
    sendContext(update: JsonObject): Promise<void>;
    submitToolResult(result: ToolResult): Promise<void>;
    on(listener: (event: CanonicalProviderEvent) => void): () => void;
    private negotiate;
    private handleMessage;
    private send;
    private reconnect;
    private csrfToken;
}
