import { EventStream } from "../events.js";
export function normalizeOpenAIResponseUsage(usage) {
    const object = (value) => value !== null && typeof value === "object" ? value : {};
    const input = object(usage.input_token_details ?? usage.input_tokens_details);
    const output = object(usage.output_token_details ?? usage.output_tokens_details);
    const cached = object(input.cached_tokens_details);
    return {
        input_text_tokens: Number(input.text_tokens ?? 0),
        cached_input_text_tokens: Number(cached.text_tokens ?? 0),
        input_audio_tokens: Number(input.audio_tokens ?? 0),
        cached_input_audio_tokens: Number(cached.audio_tokens ?? 0),
        input_image_tokens: Number(input.image_tokens ?? 0),
        cached_input_image_tokens: Number(cached.image_tokens ?? 0),
        output_text_tokens: Number(output.text_tokens ?? 0),
        output_audio_tokens: Number(output.audio_tokens ?? 0),
    };
}
export function openAIResponseCreateEvent(mode) {
    return mode === "text"
        ? { type: "response.create", response: { output_modalities: ["text"] } }
        : { type: "response.create" };
}
export class OpenAIRealtimeDriver {
    request;
    peerFactory;
    mediaDevices;
    events = new EventStream();
    connection = null;
    channel = null;
    stream = null;
    descriptor = null;
    revision = 1;
    reconnectTimer = null;
    toolNameMap = {};
    mode = "voice";
    model = "gpt-realtime";
    transcriptionModel = "gpt-4o-mini-transcribe";
    audioElements = [];
    constructor(request = globalThis.fetch.bind(globalThis), peerFactory = () => new RTCPeerConnection(), mediaDevices = globalThis.navigator?.mediaDevices) {
        this.request = request;
        this.peerFactory = peerFactory;
        this.mediaDevices = mediaDevices;
    }
    async connect(descriptor) {
        if (descriptor.connection.transport !== "webrtc") {
            throw new Error("OpenAI driver requires a WebRTC descriptor.");
        }
        this.descriptor = descriptor;
        this.revision = Number(descriptor.state.session.revision);
        this.toolNameMap = (descriptor.connection.tool_name_map ?? {});
        this.model = String(descriptor.connection.model ?? this.model);
        this.transcriptionModel = String(descriptor.connection.transcription_model ?? this.transcriptionModel);
        await this.negotiate(descriptor);
        const reconnectMs = Number(descriptor.connection.renegotiate_after_ms ?? 55 * 60 * 1000);
        this.reconnectTimer = setTimeout(() => void this.reconnect(), reconnectMs);
    }
    async disconnect() {
        if (this.reconnectTimer)
            clearTimeout(this.reconnectTimer);
        this.reconnectTimer = null;
        this.channel?.close();
        this.connection?.close();
        this.stream?.getTracks().forEach((track) => track.stop());
        this.channel = null;
        this.connection = null;
        this.stream = null;
        this.audioElements.splice(0).forEach((audio) => audio.remove());
        this.events.emit({ type: "agent.disconnected" });
    }
    async setMode(mode) {
        this.mode = mode;
        this.stream?.getAudioTracks().forEach((track) => {
            track.enabled = mode === "voice";
        });
        this.audioElements.forEach((audio) => {
            audio.muted = mode === "text";
        });
        this.events.emit({ type: "agent.mode.changed", mode });
    }
    async sendText(text) {
        this.send({
            type: "conversation.item.create",
            item: { type: "message", role: "user", content: [{ type: "input_text", text }] },
        });
        this.send(this.responseCreateEvent());
    }
    async sendContext(update) {
        const base = String(this.descriptor?.connection.session_instructions ?? "");
        this.send({
            type: "session.update",
            session: {
                instructions: `${base}\n\nRuntime context (untrusted application data):\n${JSON.stringify(update)}`,
            },
        });
    }
    async submitToolResult(result) {
        this.revision = result.state_revision;
        this.send({
            type: "conversation.item.create",
            item: {
                type: "function_call_output",
                call_id: result.call_id,
                output: JSON.stringify(result),
            },
        });
        this.send(this.responseCreateEvent());
    }
    on(listener) {
        return this.events.on(listener);
    }
    async negotiate(descriptor) {
        const bootstrapUrl = String(descriptor.connection.bootstrap_url ?? "");
        if (!bootstrapUrl)
            throw new Error("OpenAI bootstrap URL is missing.");
        const peer = this.peerFactory();
        const channel = peer.createDataChannel("oai-events");
        const opened = new Promise((resolve, reject) => {
            if (channel.readyState === "open") {
                resolve();
                return;
            }
            const timeout = setTimeout(() => reject(new Error("OpenAI Realtime data channel timed out.")), 10_000);
            channel.addEventListener("open", () => {
                clearTimeout(timeout);
                resolve();
            }, { once: true });
        });
        this.connection = peer;
        this.channel = channel;
        channel.addEventListener("message", (event) => this.handleMessage(String(event.data)));
        channel.addEventListener("open", () => this.events.emit({ type: "agent.connected" }), { once: true });
        channel.addEventListener("close", () => this.events.emit({ type: "agent.disconnected" }));
        if (this.mediaDevices) {
            this.stream = await this.mediaDevices.getUserMedia({ audio: true });
            this.stream.getTracks().forEach((track) => {
                track.enabled = this.mode === "voice";
                peer.addTrack(track, this.stream);
            });
        }
        peer.addEventListener("track", (event) => {
            if (!globalThis.document)
                return;
            const audio = document.createElement("audio");
            audio.autoplay = true;
            audio.muted = this.mode === "text";
            audio.srcObject = event.streams[0] ?? new MediaStream([event.track]);
            audio.hidden = true;
            document.body.append(audio);
            this.audioElements.push(audio);
        });
        const offer = await peer.createOffer();
        await peer.setLocalDescription(offer);
        const response = await this.request(bootstrapUrl, {
            method: "POST",
            credentials: "same-origin",
            headers: { Accept: "application/json", "Content-Type": "application/sdp", "X-CSRF-TOKEN": this.csrfToken() },
            body: offer.sdp,
        });
        const connected = await response.json();
        if (!response.ok) {
            throw new Error(String(connected.error?.message ?? "OpenAI WebRTC bootstrap failed."));
        }
        await peer.setRemoteDescription({ type: "answer", sdp: String(connected.connection.answer_sdp) });
        await opened;
    }
    handleMessage(raw) {
        try {
            const event = JSON.parse(raw);
            const type = String(event.type ?? "");
            if (type === "response.function_call_arguments.done") {
                this.events.emit({
                    type: "agent.tool.call",
                    call: {
                        id: String(event.call_id ?? event.item_id),
                        provider_call_id: String(event.call_id ?? event.item_id),
                        idempotency_key: String(event.call_id ?? event.item_id),
                        name: this.toolNameMap[String(event.name)] ?? String(event.name),
                        arguments: JSON.parse(String(event.arguments ?? "{}")),
                        base_revision: this.revision,
                    },
                });
            }
            else if (type === "response.audio_transcript.delta" || type === "response.output_audio_transcript.delta" || type === "response.output_text.delta") {
                this.events.emit({
                    type: "agent.transcript.delta",
                    role: "agent",
                    text: String(event.delta ?? ""),
                    messageId: String(event.item_id ?? event.response_id ?? ""),
                    modality: type === "response.output_text.delta" ? "text" : "audio",
                });
            }
            else if (type === "response.audio_transcript.done" || type === "response.output_audio_transcript.done" || type === "response.output_text.done") {
                this.events.emit({
                    type: "agent.transcript.final",
                    role: "agent",
                    text: String(event.transcript ?? event.text ?? ""),
                    messageId: String(event.item_id ?? event.response_id ?? event.event_id),
                    modality: type === "response.output_text.done" ? "text" : "audio",
                });
            }
            else if (type === "conversation.item.input_audio_transcription.completed") {
                const messageId = String(event.item_id ?? event.event_id);
                this.events.emit({
                    type: "agent.transcript.final",
                    role: "user",
                    text: String(event.transcript ?? ""),
                    messageId,
                    modality: "audio",
                });
                const usage = this.asObject(event.usage);
                if (Object.keys(usage).length === 0)
                    return;
                const input = this.asObject(usage.input_token_details ?? usage.input_tokens_details);
                this.events.emit({
                    type: "agent.usage",
                    usage: {
                        provider: "openai",
                        provider_event_id: String(event.event_id ?? `${messageId}:transcription`),
                        idempotency_key: String(event.event_id ?? `${messageId}:transcription`),
                        kind: "transcription",
                        model: this.transcriptionModel,
                        units: {
                            input_audio_tokens: Number(input.audio_tokens ?? usage.input_tokens ?? 0),
                            output_text_tokens: Number(usage.output_tokens ?? 0),
                        },
                        raw: usage,
                    },
                });
            }
            else if (type === "response.done") {
                const response = this.asObject(event.response);
                const usage = this.asObject(response.usage);
                if (Object.keys(usage).length === 0)
                    return;
                const eventId = String(event.event_id ?? response.id ?? `usage_${Date.now()}`);
                this.events.emit({
                    type: "agent.usage",
                    usage: {
                        provider: "openai",
                        provider_event_id: eventId,
                        idempotency_key: eventId,
                        kind: "response",
                        model: String(response.model ?? this.model),
                        units: normalizeOpenAIResponseUsage(usage),
                        raw: usage,
                    },
                });
            }
            else if (type === "error") {
                const detail = event.error;
                this.events.emit({ type: "agent.error", error: new Error(String(detail?.message ?? "OpenAI Realtime error.")) });
            }
        }
        catch (error) {
            this.events.emit({ type: "agent.error", error: error instanceof Error ? error : new Error("Invalid OpenAI event.") });
        }
    }
    send(event) {
        if (!this.channel || this.channel.readyState !== "open") {
            throw new Error("OpenAI Realtime data channel is not open.");
        }
        this.channel.send(JSON.stringify(event));
    }
    responseCreateEvent() {
        return openAIResponseCreateEvent(this.mode);
    }
    async reconnect() {
        if (!this.descriptor)
            return;
        const descriptor = this.descriptor;
        await this.disconnect();
        await this.connect(descriptor);
    }
    csrfToken() {
        return globalThis.document?.querySelector('meta[name="csrf-token"]')?.content ?? "";
    }
    asObject(value) {
        return value !== null && typeof value === "object" ? value : {};
    }
}
