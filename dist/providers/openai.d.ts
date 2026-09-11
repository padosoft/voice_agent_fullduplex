import type { CanonicalProviderEvent, ConnectionDescriptor, InteractionMode, JsonObject, ProviderUsageInput, RealtimeProviderDriver, ToolResult } from "../types.js";
type TranscriptRole = "agent" | "user";
export interface OpenAILiveTranscriptFragment extends JsonObject {
    provider_event_id: string;
    delta: string;
    start_ms: number;
    end_ms: number;
}
export interface OpenAILiveTranscriptPush {
    rowId: string;
    correction?: Extract<CanonicalProviderEvent, {
        type: "agent.transcript.final";
    }>;
}
export declare function normalizeOpenAIResponseUsage(usage: JsonObject): Record<string, number>;
export declare function openAIResponseCreateEvent(_mode?: InteractionMode, eventId?: string): JsonObject;
export declare function normalizeOpenAILiveDurationUsage(event: JsonObject, providerSessionId: string, model?: string): ProviderUsageInput | null;
/**
 * Groups GPT-Live transcript fragments into revisable display/audit rows while
 * retaining every original delta and its provider timeline interval.
 */
export declare class OpenAILiveTranscriptAssembler {
    private readonly gapMs;
    private readonly maximumFragmentsPerRow;
    private readonly rows;
    private arrivalIndex;
    constructor(gapMs?: number, maximumFragmentsPerRow?: number);
    push(role: TranscriptRole, event: JsonObject): OpenAILiveTranscriptPush | null;
    finalize(rowId: string): Extract<CanonicalProviderEvent, {
        type: "agent.transcript.final";
    }> | null;
    flushPending(): Array<Extract<CanonicalProviderEvent, {
        type: "agent.transcript.final";
    }>>;
    private closestRow;
    private find;
    private eventFor;
}
export declare class OpenAILiveDriver implements RealtimeProviderDriver {
    private readonly request;
    private readonly peerFactory;
    private readonly mediaDevices;
    private readonly events;
    private connection;
    private channel;
    private stream;
    private descriptor;
    private bootstrapDescriptor;
    private revision;
    private toolNameMap;
    private mode;
    private model;
    private backendModel;
    private providerSessionId;
    private started;
    private closed;
    private disconnected;
    private lastDurationSeconds;
    private transcript;
    private readonly transcriptTimers;
    private resolveStarted;
    private resolveClosed;
    private startedPromise;
    private closedPromise;
    private readonly audioElements;
    constructor(request?: typeof fetch, peerFactory?: () => RTCPeerConnection, mediaDevices?: MediaDevices | undefined);
    connect(descriptor: ConnectionDescriptor): Promise<void>;
    disconnect(): Promise<void>;
    setMode(mode: InteractionMode): Promise<void>;
    sendText(text: string): Promise<void>;
    sendContext(update: JsonObject): Promise<void>;
    submitToolResult(result: ToolResult): Promise<void>;
    on(listener: (event: CanonicalProviderEvent) => void): () => void;
    private resetConnectionState;
    private negotiate;
    private handleMessage;
    private handleTranscriptFragment;
    private handleDuration;
    private handleResponseEvent;
    private flushTranscripts;
    private closeVoiceTransport;
    private requestText;
    private handleTextResponse;
    private emitToolCall;
    private send;
    private cleanup;
    private emitDisconnected;
    private uniqueId;
    private csrfToken;
}
export { OpenAILiveDriver as OpenAIRealtimeDriver };
