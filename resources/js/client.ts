import type {
  ConnectionDescriptor,
  ControlTransport,
  RealtimeProviderDriver,
} from "./types.js";
import { SurfaceRegistry } from "./surface/registry.js";

export class RealtimeAgentClient {
  private unsubscribe: (() => void) | null = null;

  constructor(
    readonly surfaces: SurfaceRegistry,
    private readonly provider: RealtimeProviderDriver,
    private readonly control: ControlTransport,
  ) {}

  async connect(descriptor: ConnectionDescriptor): Promise<void> {
    this.unsubscribe = this.provider.on((event) => {
      if (event.type !== "agent.tool.call") return;

      void this.control.executeTool(event.call).then((result) =>
        this.provider.submitToolResult(result),
      );
    });

    await this.provider.connect(descriptor);

    if (this.surfaces.currentId()) {
      await this.provider.sendContext({ ui: this.surfaces.snapshot() });
    }
  }

  async disconnect(): Promise<void> {
    this.unsubscribe?.();
    this.unsubscribe = null;
    await this.provider.disconnect();
  }
}

export * from "./types.js";
export { EventStream } from "./events.js";
export { SurfaceRegistry } from "./surface/registry.js";
export { DomSurfaceAdapter } from "./surface/dom-adapter.js";
export { UiCommandExecutor } from "./surface/command-executor.js";
export { FakeRealtimeDriver } from "./providers/fake.js";
export { OpenAIRealtimeDriver } from "./providers/openai.js";
export { ElevenLabsRealtimeDriver } from "./providers/elevenlabs.js";
