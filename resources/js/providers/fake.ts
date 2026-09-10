import { EventStream } from "../events.js";
import type {
  CanonicalProviderEvent,
  ConnectionDescriptor,
  JsonObject,
  RealtimeProviderDriver,
  ToolResult,
} from "../types.js";

export class FakeRealtimeDriver implements RealtimeProviderDriver {
  private readonly events = new EventStream<CanonicalProviderEvent>();
  private connected = false;
  readonly context: JsonObject[] = [];
  readonly toolResults: ToolResult[] = [];

  async connect(descriptor: ConnectionDescriptor): Promise<void> {
    if (descriptor.connection.transport !== "fake") {
      throw new Error("Fake driver requires the fake transport descriptor.");
    }

    this.connected = true;
    this.events.emit({ type: "agent.connected" });
  }

  async disconnect(): Promise<void> {
    this.connected = false;
    this.events.emit({ type: "agent.disconnected" });
  }

  async sendText(text: string): Promise<void> {
    this.assertConnected();
    this.events.emit({ type: "agent.transcript.delta", role: "user", text });
  }

  async sendContext(update: JsonObject): Promise<void> {
    this.assertConnected();
    this.context.push(update);
  }

  async submitToolResult(result: ToolResult): Promise<void> {
    this.assertConnected();
    this.toolResults.push(result);
  }

  on(listener: (event: CanonicalProviderEvent) => void): () => void {
    return this.events.on(listener);
  }

  emit(event: CanonicalProviderEvent): void {
    this.assertConnected();
    this.events.emit(event);
  }

  async play(events: readonly CanonicalProviderEvent[], delayMs = 0): Promise<void> {
    for (const event of events) {
      if (delayMs > 0) await new Promise((resolve) => setTimeout(resolve, delayMs));
      this.emit(event);
    }
  }

  private assertConnected(): void {
    if (!this.connected) throw new Error("Fake provider is not connected.");
  }
}
