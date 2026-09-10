import { EventStream } from "../events.js";
export class FakeRealtimeDriver {
    events = new EventStream();
    connected = false;
    context = [];
    toolResults = [];
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
    async sendText(text) {
        this.assertConnected();
        this.events.emit({ type: "agent.transcript.delta", role: "user", text });
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
