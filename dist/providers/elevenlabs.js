import { EventStream } from "../events.js";
export class ElevenLabsRealtimeDriver {
    request;
    socketFactory;
    events = new EventStream();
    socket = null;
    revision = 1;
    toolNameMap = {};
    mode = "voice";
    connectedAt = null;
    durationEventId = null;
    durationRecorded = false;
    lastAgentMessageId = null;
    constructor(request = globalThis.fetch.bind(globalThis), socketFactory = (url) => new WebSocket(url)) {
        this.request = request;
        this.socketFactory = socketFactory;
    }
    async connect(descriptor) {
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
        const connected = await response.json();
        if (!response.ok)
            throw new Error(String(connected.error?.message ?? "ElevenLabs bootstrap failed."));
        this.toolNameMap = (connected.connection.tool_name_map ?? {});
        const socket = this.socketFactory(String(connected.connection.signed_url));
        this.socket = socket;
        const opened = new Promise((resolve, reject) => {
            const timeout = setTimeout(() => reject(new Error("ElevenLabs WebSocket timed out.")), 10_000);
            socket.addEventListener("open", () => {
                clearTimeout(timeout);
                resolve();
            }, { once: true });
        });
        socket.addEventListener("open", () => {
            this.connectedAt = Date.now();
            this.durationEventId = `elevenlabs-duration-${this.connectedAt}`;
            this.durationRecorded = false;
            this.send({
                type: "conversation_initiation_client_data",
                dynamic_variables: connected.connection.dynamic_variables,
                conversation_config_override: connected.connection.overrides,
            });
            this.events.emit({ type: "agent.connected" });
        });
        socket.addEventListener("message", (event) => this.handleMessage(String(event.data)));
        socket.addEventListener("close", () => {
            this.emitDuration();
            this.events.emit({ type: "agent.disconnected" });
        });
        socket.addEventListener("error", () => this.events.emit({ type: "agent.error", error: new Error("ElevenLabs WebSocket error.") }));
        await opened;
    }
    async disconnect() {
        this.emitDuration();
        this.socket?.close();
        this.socket = null;
    }
    async setMode(mode) {
        this.mode = mode;
        this.events.emit({ type: "agent.mode.changed", mode });
    }
    async sendText(text) {
        this.send({ type: "user_message", text });
    }
    async sendContext(update) {
        this.send({ type: "contextual_update", text: JSON.stringify(update) });
    }
    async submitToolResult(result) {
        this.revision = result.state_revision;
        this.send({
            type: "client_tool_result",
            tool_call_id: result.call_id,
            result: JSON.stringify(result),
            is_error: result.status === "failed" || result.status === "rejected",
        });
    }
    on(listener) {
        return this.events.on(listener);
    }
    handleMessage(raw) {
        try {
            const event = JSON.parse(raw);
            const type = String(event.type ?? "");
            if (type === "client_tool_call") {
                const call = this.asObject(event.client_tool_call);
                const providerName = String(call.tool_name ?? event.tool_name ?? event.name);
                this.events.emit({
                    type: "agent.tool.call",
                    call: {
                        id: String(call.tool_call_id ?? event.tool_call_id),
                        provider_call_id: String(call.tool_call_id ?? event.tool_call_id),
                        idempotency_key: String(call.tool_call_id ?? event.tool_call_id),
                        name: this.toolNameMap[providerName] ?? providerName,
                        arguments: this.asObject(call.parameters ?? event.parameters),
                        base_revision: this.revision,
                    },
                });
            }
            else if (type === "conversation_initiation_metadata") {
                const metadata = this.asObject(event.conversation_initiation_metadata_event);
                const conversationId = String(metadata.conversation_id ?? "");
                if (conversationId)
                    this.events.emit({ type: "agent.provider.session", providerSessionId: conversationId });
            }
            else if (type === "agent_response") {
                const response = this.asObject(event.agent_response_event);
                const messageId = String(response.response_id ?? response.event_id ?? `agent_${Date.now()}`);
                this.lastAgentMessageId = messageId;
                this.events.emit({
                    type: "agent.transcript.final",
                    role: "agent",
                    text: String(response.agent_response ?? event.text ?? ""),
                    messageId,
                    modality: this.mode === "text" ? "text" : "audio",
                });
            }
            else if (type === "agent_response_correction") {
                const correction = this.asObject(event.agent_response_correction_event);
                this.events.emit({
                    type: "agent.transcript.final",
                    role: "agent",
                    text: String(correction.corrected_agent_response ?? event.text ?? ""),
                    messageId: String(correction.response_id ?? this.lastAgentMessageId ?? correction.event_id ?? `agent_${Date.now()}`),
                    modality: this.mode === "text" ? "text" : "audio",
                    status: "corrected",
                });
            }
            else if (type === "user_transcript") {
                const transcript = this.asObject(event.user_transcription_event);
                this.events.emit({
                    type: "agent.transcript.final",
                    role: "user",
                    text: String(transcript.user_transcript ?? event.text ?? ""),
                    messageId: String(transcript.event_id ?? `user_${Date.now()}`),
                    modality: "audio",
                });
            }
            else if (type === "audio") {
                const audio = this.asObject(event.audio_event);
                this.events.emit({ type: "agent.audio.delta", audio: String(audio?.audio_base_64 ?? event.audio ?? "") });
            }
            else if (type === "ping") {
                const ping = this.asObject(event.ping_event);
                this.send({ type: "pong", event_id: ping.event_id ?? event.ping_event_id });
            }
        }
        catch (error) {
            this.events.emit({ type: "agent.error", error: error instanceof Error ? error : new Error("Invalid ElevenLabs event.") });
        }
    }
    send(event) {
        if (!this.socket || this.socket.readyState !== 1) {
            throw new Error("ElevenLabs WebSocket is not open.");
        }
        this.socket.send(JSON.stringify(event));
    }
    csrfToken() {
        return globalThis.document?.querySelector('meta[name="csrf-token"]')?.content ?? "";
    }
    emitDuration() {
        if (this.durationRecorded || this.connectedAt === null || this.durationEventId === null)
            return;
        this.durationRecorded = true;
        this.events.emit({
            type: "agent.usage",
            usage: {
                provider: "elevenlabs",
                provider_event_id: this.durationEventId,
                idempotency_key: this.durationEventId,
                kind: "duration",
                units: { duration_seconds: Math.max(0, (Date.now() - this.connectedAt) / 1000) },
                raw: { source: "browser_connection_clock" },
            },
        });
    }
    asObject(value) {
        return value !== null && typeof value === "object" ? value : {};
    }
}
