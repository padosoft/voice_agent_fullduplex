import type {
  AgentState,
  ConversationMessage,
  ConversationMessageInput,
  ControlTransport,
  JsonObject,
  SurfaceSnapshot,
  ProviderUsageInput,
  SessionAudit,
  ToolCallInput,
  ToolResult,
  UiCommand,
  UiCommandResult,
  UsageRecord,
} from "./types.js";

export class RealtimeControlError extends Error {
  constructor(
    message: string,
    readonly status: number,
    readonly code: string,
    readonly state?: AgentState,
  ) {
    super(message);
  }
}

export class LaravelControlTransport implements ControlTransport {
  constructor(
    private readonly sessionId: string,
    private readonly baseUrl = "/realtime-agent",
    private readonly request: typeof fetch = globalThis.fetch.bind(globalThis),
  ) {}

  async executeTool(input: ToolCallInput): Promise<ToolResult> {
    const body = await this.json<{ result: ToolResult }>("/tools", {
      method: "POST",
      body: {
        ...input,
        idempotency_key: input.idempotency_key ?? input.id,
        provider_call_id: input.provider_call_id ?? input.id,
      },
    });

    return body.result;
  }

  async refreshState(): Promise<AgentState> {
    return (await this.json<{ state: AgentState }>("", { method: "GET" })).state;
  }

  async syncSurface(baseRevision: number, snapshot: SurfaceSnapshot): Promise<AgentState> {
    return (await this.json<{ state: AgentState }>("/surface", {
      method: "PUT",
      body: { base_revision: baseRevision, snapshot },
    })).state;
  }

  async resolveConfirmation(id: string, accepted: boolean): Promise<ToolResult> {
    return (await this.json<{ result: ToolResult }>(`/confirmations/${encodeURIComponent(id)}`, {
      method: "POST",
      body: { accepted },
    })).result;
  }

  async completeUiCommand(
    command: UiCommand,
    baseRevision: number,
    result: UiCommandResult,
  ): Promise<AgentState> {
    return (await this.json<{ state: AgentState }>(`/commands/${encodeURIComponent(command.id)}`, {
      method: "POST",
      body: {
        base_revision: baseRevision,
        token: command.token,
        result: this.asObject(result),
        surface: result.surface,
      },
    })).state;
  }

  async finish(baseRevision: number): Promise<AgentState> {
    return (await this.json<{ state: AgentState }>("", {
      method: "DELETE",
      body: { base_revision: baseRevision },
    })).state;
  }

  async recordMessage(input: ConversationMessageInput): Promise<ConversationMessage> {
    return (await this.json<{ message: ConversationMessage }>("/messages", {
      method: "POST",
      body: input,
    })).message;
  }

  async recordUsage(input: ProviderUsageInput): Promise<UsageRecord> {
    return (await this.json<{ usage: UsageRecord }>("/usage", {
      method: "POST",
      body: input,
    })).usage;
  }

  async fetchAudit(): Promise<SessionAudit> {
    return (await this.json<{ audit: SessionAudit }>("/audit", { method: "GET" })).audit;
  }

  private async json<T>(path: string, options: { method: string; body?: JsonObject }): Promise<T> {
    const response = await this.request(
      `${this.baseUrl.replace(/\/$/, "")}/sessions/${encodeURIComponent(this.sessionId)}${path}`,
      {
        method: options.method,
        credentials: "same-origin",
        headers: {
          Accept: "application/json",
          "Content-Type": "application/json",
          "X-CSRF-TOKEN": this.csrfToken(),
        },
        body: options.body ? JSON.stringify(options.body) : undefined,
      },
    );
    const payload = await response.json() as JsonObject;

    if (!response.ok) {
      const error = (payload.error ?? {}) as JsonObject;
      throw new RealtimeControlError(
        String(error.message ?? "Realtime control request failed."),
        response.status,
        String(error.code ?? "request_failed"),
        payload.state as AgentState | undefined,
      );
    }

    return payload as T;
  }

  private csrfToken(): string {
    return globalThis.document?.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content ?? "";
  }

  private asObject(result: UiCommandResult): JsonObject {
    return JSON.parse(JSON.stringify(result)) as JsonObject;
  }
}
