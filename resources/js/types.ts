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
  "realtime-agent-message@1",
  "realtime-agent-usage@1",
  "realtime-agent-audit@1",
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

export type InteractionMode = "voice" | "text";

export interface ConversationMessageInput extends JsonObject {
  provider: string;
  provider_event_id?: string;
  idempotency_key: string;
  role: "user" | "assistant" | "system" | "tool";
  direction: "input" | "output" | "internal";
  modality: "text" | "audio" | "tool";
  status?: "completed" | "corrected" | "interrupted";
  content: string;
  metadata?: JsonObject;
  occurred_at?: string;
}

export interface ConversationMessage extends ConversationMessageInput {
  id: string;
  session_id: string;
  seq: number;
  status: "completed" | "corrected" | "interrupted";
  occurred_at: string;
}

export interface ProviderUsageInput extends JsonObject {
  provider: string;
  provider_event_id?: string;
  idempotency_key: string;
  kind: "response" | "transcription" | "duration" | "conversation";
  model?: string;
  units: Record<string, number>;
  raw?: JsonObject;
  occurred_at?: string;
}

export type UsageRecord = Omit<ProviderUsageInput, "kind"> & {
  id: string;
  session_id: string;
  seq: number;
  kind: ProviderUsageInput["kind"] | "provider_invoice";
  pricing: JsonObject | null;
  amount: string | null;
  currency: string;
  status: "estimated" | "final" | "unpriced";
  occurred_at: string;
};

export interface SessionAudit extends JsonObject {
  schema: "realtime-agent-audit@1";
  session_id: string;
  provider: string;
  provider_session_id: string | null;
  messages: ConversationMessage[];
  usage: UsageRecord[];
  tool_calls: JsonObject[];
  totals: {
    estimated: Record<string, string>;
    final: Record<string, string>;
    effective: Record<string, string>;
    unpriced_records: number;
  };
}

export type CanonicalProviderEvent =
  | { type: "agent.connected" }
  | { type: "agent.disconnected" }
  | { type: "agent.mode.changed"; mode: InteractionMode }
  | { type: "agent.provider.session"; providerSessionId: string }
  | { type: "agent.transcript.delta"; role: "agent" | "user"; text: string; messageId?: string; modality?: "text" | "audio"; metadata?: JsonObject }
  | { type: "agent.transcript.final"; role: "agent" | "user"; text: string; messageId: string; modality: "text" | "audio"; status?: "completed" | "corrected" | "interrupted"; metadata?: JsonObject }
  | { type: "agent.usage"; usage: ProviderUsageInput }
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
  setMode(mode: InteractionMode): Promise<void>;
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
  recordMessage(input: ConversationMessageInput): Promise<ConversationMessage>;
  recordUsage(input: ProviderUsageInput): Promise<UsageRecord>;
  fetchAudit(): Promise<SessionAudit>;
}

export type ConfirmationHandler = (confirmation: JsonObject) => Promise<boolean> | boolean;
