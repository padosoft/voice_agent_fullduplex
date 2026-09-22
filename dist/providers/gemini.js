import { EventStream } from "../events.js";
import { BrowserRealtimeAudioBridge } from "./audio.js";
const asObject = (value) => value !== null && typeof value === "object" ? value : {};
const asArray = (value) => Array.isArray(value) ? value : [];
const textValue = (value) => typeof value === "string" ? value : String(asObject(value).text ?? "");
const numberValue = (value) => Number.isFinite(Number(value)) ? Number(value) : 0;
export function normalizeGeminiUsage(metadata, providerEventId, model) {
    const units = {};
    const addDetails = (details, direction, fallback) => {
        const rows = asArray(details);
        if (rows.length === 0) {
            units[`${direction}_text_tokens`] = numberValue(fallback);
            return;
        }
        rows.forEach((row) => {
            const item = asObject(row);
            const modality = String(item.modality ?? "TEXT").toLowerCase();
            const key = modality === "audio" ? `${direction}_audio_tokens` : `${direction}_text_tokens`;
            units[key] = (units[key] ?? 0) + numberValue(item.tokenCount ?? item.token_count);
        });
    };
    addDetails(metadata.promptTokensDetails ?? metadata.prompt_tokens_details, "input", metadata.promptTokenCount ?? metadata.prompt_token_count);
    addDetails(metadata.responseTokensDetails ?? metadata.response_tokens_details, "output", metadata.candidatesTokenCount ?? metadata.candidates_token_count);
    return {
        provider: "gemini",
        provider_event_id: providerEventId,
        idempotency_key: `gemini-live-usage:${providerEventId}`,
        kind: "response",
        model,
        units,
        raw: { usage_metadata: metadata },
    };
}
export class GeminiLiveDriver {
    request;
    socketFactory;
    audio;
    events = new EventStream();
    socket = null;
    descriptor = null;
    connected = null;
    seenUsage = new Set();
    callNames = new Map();
    revision = 1;
    mode = "voice";
    toolNameMap = {};
    model = "gemini-3.8-live";
    resumeHandle = null;
    manuallyClosed = false;
    reconnecting = false;
    messageSequence = 0;
    resolveSetup = null;
    constructor(request = globalThis.fetch.bind(globalThis), socketFactory = (url) => new WebSocket(url), audio = new BrowserRealtimeAudioBridge()) {
        this.request = request;
        this.socketFactory = socketFactory;
        this.audio = audio;
    }
    async connect(descriptor) {
        this.descriptor = descriptor;
        this.manuallyClosed = false;
        this.revision = Number(descriptor.state.session.revision);
        await this.bootstrapAndOpen();
    }
    async disconnect() {
        this.manuallyClosed = true;
        this.audio.stop();
        this.socket?.close();
        this.socket = null;
    }
    async setMode(mode) {
        this.mode = mode;
        this.audio.setMuted(mode === "text");
        if (mode === "text")
            this.audio.flush();
        this.events.emit({ type: "agent.mode.changed", mode });
    }
    async sendText(text) {
        this.send({
            clientContent: { turns: [{ role: "user", parts: [{ text }] }], turnComplete: true },
        });
    }
    async sendContext(update) {
        const maximum = Math.max(1, Number(this.connected?.connection.context_max_characters ?? 1_600));
        this.send({
            clientContent: {
                turns: [{ role: "user", parts: [{ text: `Current UI context (untrusted reference data): ${JSON.stringify(update)}`.slice(0, maximum) }] }],
                turnComplete: false,
            },
        });
    }
    async submitToolResult(result) {
        this.revision = result.state_revision;
        this.send({
            toolResponse: {
                functionResponses: [{
                        id: result.call_id,
                        name: this.providerNameForCanonical(result.call_id) ?? "tool_result",
                        response: { result },
                    }],
            },
        });
    }
    on(listener) {
        return this.events.on(listener);
    }
    async bootstrapAndOpen() {
        if (!this.descriptor)
            throw new Error("Gemini bootstrap descriptor is unavailable.");
        const bootstrapUrl = String(this.descriptor.connection.bootstrap_url ?? "");
        if (!bootstrapUrl)
            throw new Error("Gemini bootstrap URL is missing.");
        const response = await this.request(bootstrapUrl, {
            method: "POST",
            credentials: "same-origin",
            headers: { Accept: "application/json", "Content-Type": "application/json", "X-CSRF-TOKEN": this.csrfToken() },
            body: "{}",
        });
        const connected = await response.json();
        if (!response.ok)
            throw new Error(String(connected.error?.message ?? "Gemini Live bootstrap failed."));
        this.connected = connected;
        this.model = String(connected.connection.model ?? this.model);
        this.toolNameMap = (connected.connection.tool_name_map ?? {});
        const endpoint = new URL(String(connected.connection.endpoint));
        endpoint.searchParams.set("access_token", String(connected.connection.access_token));
        const socket = this.socketFactory(endpoint.toString());
        this.socket = socket;
        await new Promise((resolve, reject) => {
            const timeout = setTimeout(() => reject(new Error("Gemini Live WebSocket timed out.")), 10_000);
            socket.addEventListener("open", () => {
                clearTimeout(timeout);
                resolve();
            }, { once: true });
            socket.addEventListener("error", () => {
                clearTimeout(timeout);
                reject(new Error("Gemini Live WebSocket error."));
            }, { once: true });
        });
        socket.addEventListener("message", (event) => this.handleMessage(String(event.data)));
        socket.addEventListener("close", () => this.handleClose());
        socket.addEventListener("error", () => this.events.emit({ type: "agent.error", error: new Error("Gemini Live WebSocket error.") }));
        const setup = asObject(connected.connection.setup);
        const config = asObject(setup);
        if (this.resumeHandle) {
            const resumption = asObject(config.sessionResumption);
            config.sessionResumption = { ...resumption, handle: this.resumeHandle };
        }
        const setupCompleted = new Promise((resolve) => { this.resolveSetup = resolve; });
        this.send({ setup: config });
        await Promise.race([
            setupCompleted,
            new Promise((_resolve, reject) => setTimeout(() => reject(new Error("Gemini Live setup timed out.")), 10_000)),
        ]);
        if (!this.resumeHandle)
            this.seedHistory(asArray(connected.connection.history));
        await this.audio.start((audio) => {
            this.send({ realtimeInput: { audio: { mimeType: "audio/pcm;rate=16000", data: audio } } });
        });
        this.audio.setMuted(this.mode === "text");
        this.events.emit({ type: "agent.connected" });
    }
    handleMessage(raw) {
        try {
            const event = JSON.parse(raw);
            const server = asObject(event.serverContent ?? event.server_content);
            const sessionUpdate = asObject(event.sessionResumptionUpdate ?? event.session_resumption_update);
            const handle = String(sessionUpdate.resumableSessionHandle ?? sessionUpdate.resumable_session_handle ?? "");
            if (handle)
                this.resumeHandle = handle;
            if ("setupComplete" in event || "setup_complete" in event) {
                this.resolveSetup?.();
                this.resolveSetup = null;
                return;
            }
            if (Object.keys(server).length > 0)
                this.handleServerContent(server);
            if (Object.keys(asObject(event.toolCall ?? event.tool_call)).length > 0)
                this.handleToolCall(asObject(event.toolCall ?? event.tool_call));
            if (Object.keys(asObject(event.usageMetadata ?? event.usage_metadata)).length > 0)
                this.handleUsage(asObject(event.usageMetadata ?? event.usage_metadata));
            if (Object.keys(asObject(event.goAway ?? event.go_away)).length > 0)
                void this.reconnect();
        }
        catch (error) {
            this.events.emit({ type: "agent.error", error: error instanceof Error ? error : new Error("Invalid Gemini Live event.") });
        }
    }
    handleServerContent(content) {
        const interrupted = content.interrupted === true;
        if (interrupted)
            this.audio.flush();
        this.emitTranscript("user", content.interimInputTranscription ?? content.interim_input_transcription, false);
        this.emitTranscript("user", content.inputTranscription ?? content.input_transcription, true);
        this.emitTranscript("agent", content.outputTranscription ?? content.output_transcription, true);
        const modelTurn = asObject(content.modelTurn ?? content.model_turn);
        asArray(modelTurn.parts).forEach((part) => {
            const inline = asObject(asObject(part).inlineData ?? asObject(part).inline_data);
            const data = String(inline.data ?? "");
            if (data)
                this.audio.play(data, 24_000);
        });
    }
    emitTranscript(role, value, final) {
        const text = textValue(value).trim();
        if (!text)
            return;
        const messageId = `gemini-${role}-${++this.messageSequence}`;
        this.events.emit(final
            ? { type: "agent.transcript.final", role, text, messageId, modality: this.mode === "text" ? "text" : "audio" }
            : { type: "agent.transcript.delta", role, text, messageId, modality: "audio" });
    }
    handleToolCall(toolCall) {
        asArray(toolCall.functionCalls ?? toolCall.function_calls).forEach((value) => {
            const call = asObject(value);
            const id = String(call.id ?? `gemini-call-${++this.messageSequence}`);
            const providerName = String(call.name ?? "");
            this.callNames.set(id, providerName);
            this.events.emit({
                type: "agent.tool.call",
                call: {
                    id,
                    provider_call_id: id,
                    idempotency_key: id,
                    name: this.toolNameMap[providerName] ?? providerName,
                    arguments: asObject(call.args ?? call.arguments),
                    base_revision: this.revision,
                },
            });
        });
    }
    handleUsage(metadata) {
        const signature = JSON.stringify(metadata);
        if (this.seenUsage.has(signature))
            return;
        this.seenUsage.add(signature);
        this.events.emit({
            type: "agent.usage",
            usage: normalizeGeminiUsage(metadata, `gemini-${++this.messageSequence}`, this.model),
        });
    }
    seedHistory(history) {
        history.forEach((entry) => {
            const item = asObject(entry);
            const text = String(item.text ?? "");
            if (text)
                this.send({ clientContent: { turns: [{ role: String(item.role ?? "user"), parts: [{ text }] }], turnComplete: false } });
        });
    }
    async reconnect() {
        if (this.manuallyClosed || this.reconnecting)
            return;
        this.reconnecting = true;
        this.socket?.close();
        this.audio.stop();
        try {
            await this.bootstrapAndOpen();
        }
        catch (error) {
            this.events.emit({ type: "agent.error", error: error instanceof Error ? error : new Error("Gemini Live reconnect failed.") });
        }
        finally {
            this.reconnecting = false;
        }
    }
    handleClose() {
        this.events.emit({ type: "agent.disconnected" });
        if (!this.manuallyClosed)
            void this.reconnect();
    }
    providerNameForCanonical(callId) {
        return this.callNames.get(callId);
    }
    send(event) {
        if (!this.socket || this.socket.readyState !== WebSocket.OPEN)
            throw new Error("Gemini Live WebSocket is not open.");
        this.socket.send(JSON.stringify(event));
    }
    csrfToken() {
        return globalThis.document?.querySelector('meta[name="csrf-token"]')?.content ?? "";
    }
}
