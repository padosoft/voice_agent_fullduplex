import type {
  CanonicalProviderEvent,
  ConnectionDescriptor,
  JsonObject,
  RealtimeProviderDriver,
  ToolResult,
} from "../types.js";

export class OpenAIRealtimeDriver implements RealtimeProviderDriver {
  async connect(_descriptor: ConnectionDescriptor): Promise<void> {
    throw new Error("OpenAI WebRTC support is a Phase 3 adapter extension point.");
  }
  async disconnect(): Promise<void> {}
  async sendContext(_update: JsonObject): Promise<void> {}
  async submitToolResult(_result: ToolResult): Promise<void> {}
  on(_listener: (event: CanonicalProviderEvent) => void): () => void {
    return () => undefined;
  }
}
