import type { CanonicalProviderEvent, ConnectionDescriptor, InteractionMode, JsonObject, RealtimeProviderDriver, ToolResult } from "../types.js";
import { type RealtimeAudioBridge } from "./audio.js";
type WebSocketFactory = (url: string, protocols?: string | string[]) => WebSocket;
/** Tracks local, billable audio frames for xAI because the realtime protocol
 * bills both input and output audio by duration. */
export declare function xaiAudioUsage(providerEventId: string, model: string, direction: "input" | "output", seconds: number): {
    provider: "xai";
    provider_event_id: string;
    idempotency_key: string;
    kind: "duration";
    model: string;
    units: Record<string, number>;
    raw: JsonObject;
};
export declare class XaiVoiceDriver implements RealtimeProviderDriver {
    private readonly request;
    private readonly socketFactory;
    private readonly audio;
    private readonly events;
    private socket;
    private descriptor;
    private connected;
    private revision;
    private mode;
    private model;
    private toolNameMap;
    private readonly inputTranscripts;
    private manualClose;
    private reconnecting;
    private eventSequence;
    private resolveConfigured;
    private meterKey;
    private inputAudioSeconds;
    private outputAudioSeconds;
    private emittedInputAudioSeconds;
    private emittedOutputAudioSeconds;
    constructor(request?: typeof fetch, socketFactory?: WebSocketFactory, audio?: RealtimeAudioBridge);
    connect(descriptor: ConnectionDescriptor): Promise<void>;
    disconnect(): Promise<void>;
    setMode(mode: InteractionMode): Promise<void>;
    sendText(text: string): Promise<void>;
    sendContext(update: JsonObject): Promise<void>;
    submitToolResult(result: ToolResult): Promise<void>;
    on(listener: (event: CanonicalProviderEvent) => void): () => void;
    private bootstrapAndOpen;
    private handleMessage;
    private handleInputTranscript;
    private handleFunctionCall;
    private seedHistory;
    private handleClose;
    private reconnect;
    private flushAudioUsage;
    private send;
    private recordTextInputUsage;
    private csrfToken;
}
export {};
