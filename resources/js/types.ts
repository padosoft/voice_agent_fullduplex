export type JsonValue = string | number | boolean | null | JsonObject | JsonValue[];
export type JsonObject = Record<string, unknown>;

export const WIRE_SCHEMAS = [
  "realtime-agent-state@1",
  "realtime-agent-surface@1",
  "realtime-agent-tool-call@1",
  "realtime-agent-tool-result@1",
  "realtime-agent-ui-command@1",
  "realtime-agent-event@1",
  "realtime-agent-goal@1",
  "realtime-agent-confirmation@1",
  "realtime-agent-connection@1",
] as const;

export interface GoalState extends JsonObject {
  id: string;
  label: string;
  status: "pending" | "active" | "completed" | "failed" | "skipped" | "cancelled";
  required: boolean;
  source: "application" | "agent" | "user";
}

export interface ConfirmationRequest extends JsonObject {
  id: string;
  call: ToolCallInput;
  requested_at: string;
}

export interface SurfaceComponent {
  type: string;
  label: string;
  state: JsonObject;
  actions: string[];
}

export interface SurfaceSnapshot extends JsonObject {
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
  snapshot: () => SurfaceSnapshot | {
    title: string | null;
    components: Record<string, SurfaceComponent>;
    revision?: number;
  };
  actions: Record<string, SurfaceActionHandler>;
}

export interface UiCommand extends JsonObject {
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
  token: string;
}

export interface UiCommandResult {
  command_id: string;
  status: "completed" | "failed";
  output?: unknown;
  error?: { code: string; message: string };
  surface?: SurfaceSnapshot;
}

export interface ToolCallInput {
  id: string;
  name: string;
  arguments: JsonObject;
  base_revision: number;
  idempotency_key?: string;
  provider_call_id?: string;
}

export type CanonicalProviderEvent =
  | { type: "agent.connected" }
  | { type: "agent.disconnected" }
  | { type: "agent.transcript.delta"; role: "agent" | "user"; text: string }
  | { type: "agent.audio.delta"; audio: string }
  | { type: "agent.tool.call"; call: ToolCallInput }
  | { type: "agent.error"; error: Error };

export type RealtimeAgentClientEvent =
  | CanonicalProviderEvent
  | { type: "state.updated"; state: AgentState }
  | { type: "surface.synced"; surface: SurfaceSnapshot }
  | { type: "confirmation.requested"; confirmation: JsonObject };

export interface AgentState extends JsonObject {
  schema: "realtime-agent-state@1";
  session: JsonObject & { id: string; revision: number; status: string };
  pending: JsonObject & { actions: UiCommand[]; confirmations: JsonObject[] };
}

export interface ConnectionDescriptor {
  session_id: string;
  provider: string;
  connection: JsonObject;
  state: AgentState;
}

export interface ToolResult {
  call_id: string;
  status: "completed" | "failed" | "confirmation_required" | "awaiting_client" | "rejected";
  output?: JsonObject | null;
  error?: JsonObject | null;
  state_revision: number;
}

export interface RealtimeProviderDriver {
  connect(descriptor: ConnectionDescriptor): Promise<void>;
  disconnect(): Promise<void>;
  sendText(text: string): Promise<void>;
  sendContext(update: JsonObject): Promise<void>;
  submitToolResult(result: ToolResult): Promise<void>;
  on(listener: (event: CanonicalProviderEvent) => void): () => void;
}

export interface ControlTransport {
  executeTool(input: ToolCallInput): Promise<ToolResult>;
  refreshState(): Promise<AgentState>;
  syncSurface(baseRevision: number, surface: SurfaceSnapshot): Promise<AgentState>;
  resolveConfirmation(id: string, accepted: boolean): Promise<ToolResult>;
  completeUiCommand(
    command: UiCommand,
    baseRevision: number,
    result: UiCommandResult,
  ): Promise<AgentState>;
  finish(baseRevision: number): Promise<AgentState>;
}

export type ConfirmationHandler = (confirmation: JsonObject) => Promise<boolean> | boolean;
