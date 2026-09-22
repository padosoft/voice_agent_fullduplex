import { describe, expect, it, vi } from "vitest";
import { RealtimeAgentClient } from "../client.js";
import { FakeRealtimeDriver } from "../providers/fake.js";
import { SurfaceRegistry } from "../surface/registry.js";
import type {
  AgentState,
  ConnectionDescriptor,
  ControlTransport,
  ConversationMessage,
  ConversationMessageInput,
  ProviderUsageInput,
  SessionAudit,
  ToolCallInput,
  UsageRecord,
} from "../types.js";

const state: AgentState = {
  schema: "realtime-agent-state@1",
  session: { id: "session-1", revision: 1, status: "active" },
  pending: { actions: [], confirmations: [] },
};

describe("text continuation audit", () => {
  it("keeps the provider connection while switching to text and persists both sides", async () => {
    const messages: ConversationMessageInput[] = [];
    const usage: ProviderUsageInput[] = [];
    const control: ControlTransport = {
      executeTool: vi.fn(),
      refreshState: vi.fn(async () => state),
      syncSurface: vi.fn(async () => state),
      resolveConfirmation: vi.fn(),
      completeUiCommand: vi.fn(async () => state),
      finish: vi.fn(async () => state),
      recordMessage: vi.fn(async (input) => {
        messages.push(input);
        return { ...input, id: `message-${messages.length}`, session_id: "session-1", seq: messages.length, status: input.status ?? "completed", occurred_at: new Date().toISOString() } as ConversationMessage;
      }),
      recordUsage: vi.fn(async (input) => {
        usage.push(input);
        return { ...input, id: "usage-1", session_id: "session-1", seq: 1, pricing: {}, amount: "0.00000000", currency: "USD", status: "final", occurred_at: new Date().toISOString() } as UsageRecord;
      }),
      fetchAudit: vi.fn(async () => ({
        schema: "realtime-agent-audit@1",
        session_id: "session-1",
        provider: "fake",
        provider_session_id: "fake_session-1",
        messages: [],
        usage: [],
        tool_calls: [],
        totals: { estimated: {}, final: { USD: "0.00000000" }, effective: { USD: "0.00000000" }, unpriced_records: 0 },
      } as SessionAudit)),
    };
    const descriptor: ConnectionDescriptor = {
      session_id: "session-1",
      provider: "fake",
      connection: { transport: "fake" },
      state,
    };
    const client = new RealtimeAgentClient(new SurfaceRegistry(), new FakeRealtimeDriver(), control);

    await client.connect(descriptor);
    await client.switchToText();
    await client.sendText("Continue here");
    await new Promise((resolve) => setTimeout(resolve, 0));

    expect(messages.map((message) => [message.role, message.content])).toEqual([
      ["user", "Continue here"],
      ["assistant", "Fake response: Continue here"],
    ]);
    expect(usage).toHaveLength(1);
    expect(usage[0]?.model).toBe("fake-realtime");
    expect(await client.audit()).toMatchObject({ schema: "realtime-agent-audit@1" });
  });

  it("serializes provider tool calls so each result carries the latest revision", async () => {
    const provider = new FakeRealtimeDriver();
    const started: string[] = [];
    const release: Record<string, () => void> = {};
    const control: ControlTransport = {
      executeTool: vi.fn((call: ToolCallInput) => new Promise((resolve) => {
        started.push(call.id);
        release[call.id] = () => resolve({ call_id: call.id, status: "completed", output: {}, state_revision: started.length + 1 });
      })),
      refreshState: vi.fn(async () => state),
      syncSurface: vi.fn(async () => state),
      resolveConfirmation: vi.fn(),
      completeUiCommand: vi.fn(async () => state),
      finish: vi.fn(async () => state),
      recordMessage: vi.fn(),
      recordUsage: vi.fn(),
      fetchAudit: vi.fn(),
    };
    const client = new RealtimeAgentClient(new SurfaceRegistry(), provider, control);

    await client.connect({ session_id: "session-1", provider: "fake", connection: { transport: "fake" }, state });
    provider.emit({ type: "agent.tool.call", call: { id: "first", name: "runtime.state.get", arguments: {}, base_revision: 1 } });
    provider.emit({ type: "agent.tool.call", call: { id: "second", name: "runtime.state.get", arguments: {}, base_revision: 1 } });

    await vi.waitFor(() => expect(started).toEqual(["first"]));
    release.first?.();
    await vi.waitFor(() => expect(started).toEqual(["first", "second"]));
    release.second?.();
    await client.disconnect();

    expect(provider.toolResults.map((result) => result.call_id)).toEqual(["first", "second"]);
  });
});
