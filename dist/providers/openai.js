import { EventStream } from "../events.js";
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
        this.events.emit({ type: "agent.disconnected" });
    }
    async sendText(text) {
        this.send({
            type: "conversation.item.create",
            item: { type: "message", role: "user", content: [{ type: "input_text", text }] },
        });
        this.send({ type: "response.create" });
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
        this.send({ type: "response.create" });
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
            this.stream.getTracks().forEach((track) => peer.addTrack(track, this.stream));
        }
        peer.addEventListener("track", (event) => {
            if (!globalThis.document)
                return;
            const audio = document.createElement("audio");
            audio.autoplay = true;
            audio.srcObject = event.streams[0] ?? new MediaStream([event.track]);
            audio.hidden = true;
            document.body.append(audio);
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
            else if (type === "response.audio_transcript.delta" || type === "response.output_audio_transcript.delta") {
                this.events.emit({ type: "agent.transcript.delta", role: "agent", text: String(event.delta ?? "") });
            }
            else if (type === "conversation.item.input_audio_transcription.completed") {
                this.events.emit({ type: "agent.transcript.delta", role: "user", text: String(event.transcript ?? "") });
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
}
