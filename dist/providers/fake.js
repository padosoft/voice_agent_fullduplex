import { EventStream } from "../events.js";
export class FakeRealtimeDriver {
    events = new EventStream();
    connected = false;
    context = [];
    toolResults = [];
    mode = "voice";
    turn = 0;
    async connect(descriptor) {
        if (descriptor.connection.transport !== "fake") {
            throw new Error("Fake driver requires the fake transport descriptor.");
        }
        this.connected = true;
        this.events.emit({ type: "agent.connected" });
    }
    async disconnect() {
        this.connected = false;
        this.events.emit({ type: "agent.disconnected" });
    }
    async setMode(mode) {
        this.assertConnected();
        this.mode = mode;
        this.events.emit({ type: "agent.mode.changed", mode });
    }
    async sendText(text) {
        this.assertConnected();
        this.turn += 1;
        const messageId = `fake_agent_${this.turn}`;
        const response = `Fake response: ${text}`;
        this.events.emit({
            type: "agent.transcript.final",
            role: "agent",
            text: response,
            messageId,
            modality: "text",
        });
        this.events.emit({
            type: "agent.usage",
            usage: {
                provider: "fake",
                provider_event_id: `fake_usage_${this.turn}`,
                idempotency_key: `fake_usage_${this.turn}`,
                kind: "response",
                model: "fake-realtime",
                units: {
                    input_text_tokens: Math.ceil(text.length / 4),
                    output_text_tokens: Math.ceil(response.length / 4),
                },
                raw: { deterministic: true },
            },
        });
    }
    async sendContext(update) {
        this.assertConnected();
        this.context.push(update);
    }
    async submitToolResult(result) {
        this.assertConnected();
        this.toolResults.push(result);
    }
    on(listener) {
        return this.events.on(listener);
    }
    emit(event) {
        this.assertConnected();
        this.events.emit(event);
    }
    async play(events, delayMs = 0) {
        for (const event of events) {
            if (delayMs > 0)
                await new Promise((resolve) => setTimeout(resolve, delayMs));
            this.emit(event);
        }
    }
    assertConnected() {
        if (!this.connected)
            throw new Error("Fake provider is not connected.");
    }
}
