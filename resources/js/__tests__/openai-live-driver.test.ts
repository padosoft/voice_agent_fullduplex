import { describe, expect, it, vi } from "vitest";
import { OpenAILiveDriver } from "../providers/openai.js";
import type { AgentState, CanonicalProviderEvent, ConnectionDescriptor } from "../types.js";

class FakeDataChannel extends EventTarget {
  readyState: RTCDataChannelState = "connecting";
  readonly sent: Record<string, unknown>[] = [];

  open(): void {
    this.readyState = "open";
    this.dispatchEvent(new Event("open"));
  }

  receive(event: Record<string, unknown>): void {
    this.dispatchEvent(new MessageEvent("message", { data: JSON.stringify(event) }));
  }

  send(payload: string): void {
    const event = JSON.parse(payload) as Record<string, unknown>;
    this.sent.push(event);

    if (event.type === "session.close") {
      this.receive({
        type: "session.closed",
        event_id: "closed_19",
        usage: { seconds: 19 },
        reason: "close_requested",
      });
    }
  }

  close(): void {
    this.readyState = "closed";
    this.dispatchEvent(new Event("close"));
  }
}

class FakePeerConnection extends EventTarget {
  readonly channel = new FakeDataChannel();
  remoteDescription: RTCSessionDescriptionInit | null = null;

  createDataChannel(): RTCDataChannel {
    return this.channel as unknown as RTCDataChannel;
  }

  async createOffer(): Promise<RTCSessionDescriptionInit> {
    return { type: "offer", sdp: "offer-sdp" };
  }

  async setLocalDescription(): Promise<void> {}

  async setRemoteDescription(description: RTCSessionDescriptionInit): Promise<void> {
    this.remoteDescription = description;
    this.channel.open();
    this.channel.receive({ type: "session.started", event_id: "started_1", session: { id: "live_123" } });
  }

  addTrack(): RTCRtpSender {
    return {} as RTCRtpSender;
  }

  close(): void {}
}

const state: AgentState = {
  schema: "realtime-agent-state@1",
  session: { id: "session-1", revision: 7, status: "active" },
  pending: { actions: [], confirmations: [] },
};

const initialDescriptor: ConnectionDescriptor = {
  session_id: "session-1",
  provider: "openai",
  connection: {
    transport: "webrtc",
    api_variant: "live",
    bootstrap_url: "/realtime-agent/sessions/session-1/connect",
    model: "gpt-live-1",
    backend_model: "gpt-5.6-terra",
  },
  state,
};

describe("OpenAI GPT-Live browser driver", () => {
  it("uses Live commands and normalizes nested tools, transcripts, and usage", async () => {
    const peer = new FakePeerConnection();
    const request = vi.fn(async () => new Response(JSON.stringify({
      ...initialDescriptor,
      connection: {
        ...initialDescriptor.connection,
        answer_sdp: "answer-sdp",
        provider_session_id: "live_123",
        text_url: "/realtime-agent/sessions/session-1/text",
        tool_name_map: { runtime_state_get: "runtime.state.get" },
        close_timeout_ms: 0,
      },
    }), { status: 200, headers: { "Content-Type": "application/json" } }));
    request.mockImplementation(async (input) => {
      if (String(input).endsWith("/text")) {
        return new Response(JSON.stringify({
          response: {
            id: "resp_text_1",
            model: "gpt-5.6-terra",
            output: [{
              id: "msg_text_1",
              type: "message",
              role: "assistant",
              content: [{ type: "output_text", text: "Written continuation." }],
            }],
            _realtime_agent: { message_id: "msg_text_1", persisted_server_side: true },
          },
        }), { status: 200, headers: { "Content-Type": "application/json" } });
      }

      return new Response(JSON.stringify({
        ...initialDescriptor,
        connection: {
          ...initialDescriptor.connection,
          answer_sdp: "answer-sdp",
          provider_session_id: "live_123",
          text_url: "/realtime-agent/sessions/session-1/text",
          tool_name_map: { runtime_state_get: "runtime.state.get" },
          close_timeout_ms: 0,
        },
      }), { status: 200, headers: { "Content-Type": "application/json" } });
    });
    const driver = new OpenAILiveDriver(
      request as unknown as typeof fetch,
      () => peer as unknown as RTCPeerConnection,
      undefined,
    );
    const events: CanonicalProviderEvent[] = [];
    driver.on((event) => events.push(event));

    await driver.connect(initialDescriptor);
    await driver.sendText("The exact order is A0042.");

    expect(request).toHaveBeenCalledWith(
      "/realtime-agent/sessions/session-1/connect",
      expect.objectContaining({ method: "POST", body: "offer-sdp" }),
    );
    expect(peer.remoteDescription).toEqual({ type: "answer", sdp: "answer-sdp" });
    expect(peer.channel.sent).toEqual(expect.arrayContaining([
      expect.objectContaining({
        type: "response.item.create",
        item: { type: "message", role: "user", content: [{ type: "input_text", text: "The exact order is A0042." }] },
      }),
      expect.objectContaining({ type: "response.create" }),
    ]));
    expect(peer.channel.sent.find((event) => event.type === "response.create")).not.toHaveProperty("response");

    peer.channel.receive({
      type: "response.event",
      event_id: "outer_tool_1",
      delegation_id: "delegation_1",
      event: {
        type: "response.output_item.done",
        item: {
          type: "function_call",
          call_id: "call_1",
          name: "runtime_state_get",
          arguments: "{}",
        },
      },
    });
    peer.channel.receive({
      type: "response.event",
      event_id: "outer_completed_1",
      delegation_id: "delegation_1",
      event: {
        type: "response.completed",
        response: {
          id: "resp_1",
          model: "gpt-5.6-terra",
          usage: { input_tokens: 25, output_tokens: 10, input_tokens_details: { cached_tokens: 5 } },
        },
      },
    });
    peer.channel.receive({
      type: "session.output_transcript.delta",
      event_id: "caption_1",
      delta: "Done.",
      start_ms: 100,
      end_ms: 300,
    });
    peer.channel.receive({
      type: "session.usage.updated",
      event_id: "duration_12",
      usage: { seconds: 12 },
    });
    await driver.setMode("text");
    await driver.sendText("Continue in writing.");
    await driver.disconnect();

    expect(peer.channel.sent).toEqual(expect.arrayContaining([
      expect.objectContaining({ type: "session.close" }),
    ]));
    expect(request).toHaveBeenCalledWith(
      "/realtime-agent/sessions/session-1/text",
      expect.objectContaining({
        method: "POST",
        body: JSON.stringify({ type: "message", message: "Continue in writing." }),
      }),
    );

    expect(events).toEqual(expect.arrayContaining([
      { type: "agent.provider.session", providerSessionId: "live_123" },
      { type: "agent.connected" },
      expect.objectContaining({
        type: "agent.tool.call",
        call: expect.objectContaining({ id: "call_1", name: "runtime.state.get", base_revision: 7 }),
      }),
      expect.objectContaining({
        type: "agent.transcript.final",
        text: "Done.",
        modality: "audio",
      }),
      expect.objectContaining({
        type: "agent.transcript.final",
        text: "Written continuation.",
        modality: "text",
        metadata: expect.objectContaining({ persisted_server_side: true }),
      }),
      expect.objectContaining({
        type: "agent.usage",
        usage: expect.objectContaining({
          idempotency_key: "openai-live-response:resp_1",
          model: "gpt-5.6-terra",
          units: expect.objectContaining({ input_text_tokens: 25, cached_input_text_tokens: 5, output_text_tokens: 10 }),
        }),
      }),
      expect.objectContaining({
        type: "agent.usage",
        usage: expect.objectContaining({
          idempotency_key: "openai-live-duration:live_123",
          units: { duration_seconds: 19 },
          raw: expect.objectContaining({ final: true }),
        }),
      }),
      { type: "agent.disconnected" },
    ]));
  });
});
