export type JsonObject = Record<string, unknown>;

export interface SurfaceComponent {
  type: string;
  label: string;
  state: JsonObject;
  actions: string[];
}

export interface SurfaceSnapshot {
  surface: string;
  revision?: number;
  title: string | null;
  components: Record<string, SurfaceComponent>;
}

export interface SurfaceActionContext extends JsonObject {
  target: string;
}

export type SurfaceActionHandler = (
  context: SurfaceActionContext,
) => Promise<unknown> | unknown;

export interface SurfaceDefinition {
  id: string;
  snapshot: () => SurfaceSnapshot | Omit<SurfaceSnapshot, "surface">;
  actions: Record<string, SurfaceActionHandler>;
}

export interface UiCommand {
  id: string;
  session_id: string;
  call_id: string;
  base_revision: number;
  surface: string;
  action: string;
  target: string;
  arguments: JsonObject;
  expires_at: string;
  nonce: string;
}

export interface UiCommandResult {
  command_id: string;
  status: "completed" | "failed";
  output?: unknown;
  error?: { code: string; message: string };
  surface?: SurfaceSnapshot;
}

export type CanonicalProviderEvent =
  | { type: "agent.connected" }
  | { type: "agent.disconnected" }
  | {
      type: "agent.tool.call";
      call: {
        id: string;
        name: string;
        arguments: JsonObject;
        base_revision: number;
      };
    }
  | { type: "agent.error"; error: Error };

export interface ConnectionDescriptor {
  session_id: string;
  provider: string;
  connection: JsonObject;
  state: JsonObject;
}

export interface ToolResult {
  call_id: string;
  status: "completed" | "failed";
  output?: JsonObject;
  error?: JsonObject;
  state_revision: number;
}

export interface RealtimeProviderDriver {
  connect(descriptor: ConnectionDescriptor): Promise<void>;
  disconnect(): Promise<void>;
  sendContext(update: JsonObject): Promise<void>;
  submitToolResult(result: ToolResult): Promise<void>;
  on(listener: (event: CanonicalProviderEvent) => void): () => void;
}

export interface ControlTransport {
  executeTool(input: {
    id: string;
    name: string;
    arguments: JsonObject;
    base_revision: number;
  }): Promise<ToolResult>;
}
