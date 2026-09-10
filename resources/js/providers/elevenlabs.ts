import type {
  CanonicalProviderEvent,
  ConnectionDescriptor,
  JsonObject,
  RealtimeProviderDriver,
  ToolResult,
} from "../types.js";

export class ElevenLabsRealtimeDriver implements RealtimeProviderDriver {
  async connect(_descriptor: ConnectionDescriptor): Promise<void> {
    throw new Error("ElevenLabs WebSocket support is a Phase 4 adapter extension point.");
  }
  async disconnect(): Promise<void> {}
  async sendContext(_update: JsonObject): Promise<void> {}
  async submitToolResult(_result: ToolResult): Promise<void> {}
  on(_listener: (event: CanonicalProviderEvent) => void): () => void {
    return () => undefined;
  }
}
