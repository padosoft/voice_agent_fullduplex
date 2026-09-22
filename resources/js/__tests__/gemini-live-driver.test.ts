import { describe, expect, it, vi } from "vitest";
import { GeminiLiveDriver, normalizeGeminiUsage } from "../providers/gemini.js";
import type { AgentState, CanonicalProviderEvent, ConnectionDescriptor } from "../types.js";
import type { RealtimeAudioBridge } from "../providers/audio.js";

class FakeSocket extends EventTarget {
  readyState = WebSocket.CONNECTING;
  readonly sent: Record<string, unknown>[] = [];

  open(): void {
    this.readyState = WebSocket.OPEN;
    this.dispatchEvent(new Event("open"));
  }

  receive(value: Record<string, unknown>): void {
    this.dispatchEvent(new MessageEvent("message", { data: JSON.stringify(value) }));
  }

  send(payload: string): void {
    this.sent.push(JSON.parse(payload) as Record<string, unknown>);
  }

  close(): void {
    this.readyState = WebSocket.CLOSED;
    this.dispatchEvent(new Event("close"));
  }
}

class FakeAudio implements RealtimeAudioBridge {
  callback: ((audio: string, seconds: number) => void) | null = null;
  flushed = false;

  async start(callback: (audio: string, seconds: number) => void): Promise<void> { this.callback = callback; }
  stop(): void {}
  setMuted(): void {}
  play(): number { return 0.02; }
  flush(): void { this.flushed = true; }
}

const state: AgentState = {
  schema: "realtime-agent-state@1",
  session: { id: "gemini-session", revision: 4, status: "active" },
  pending: { actions: [], confirmations: [] },
};

const descriptor: ConnectionDescriptor = {
  session_id: "gemini-session",
  provider: "gemini",
  connection: { transport: "websocket", bootstrap_url: "/realtime-agent/sessions/gemini-session/connect" },
  state,
};

describe("Gemini Live browser driver", () => {
  it("uses constrained bootstrap, PCM events, transcripts, blocking tools, and usage metadata", async () => {
    const socket = new FakeSocket();
    const audio = new FakeAudio();
    const request = vi.fn(async () => new Response(JSON.stringify({
      ...descriptor,
      connection: {
        transport: "websocket",
        endpoint: "wss://gemini.test/live",
        access_token: "short-lived-token",
        model: "gemini-3.8-live",
        setup: { model: "models/gemini-3.8-live", responseModalities: ["AUDIO"] },
        tool_name_map: { runtime_state_get: "runtime.state.get" },
        history: [],
      },
    }), { headers: { "Content-Type": "application/json" } }));
    const factory = vi.fn(() => socket as unknown as WebSocket);
    const driver = new GeminiLiveDriver(request as unknown as typeof fetch, factory, audio);
    const events: CanonicalProviderEvent[] = [];
    driver.on((event) => events.push(event));
    const connecting = driver.connect(descriptor);
    await vi.waitFor(() => expect(factory).toHaveBeenCalledOnce());
    socket.open();
    await Promise.resolve();
    socket.receive({ setupComplete: {} });
    await connecting;

    expect(request).toHaveBeenCalledWith("/realtime-agent/sessions/gemini-session/connect", expect.objectContaining({ method: "POST" }));
    expect(socket.sent).toContainEqual({ setup: { model: "models/gemini-3.8-live", responseModalities: ["AUDIO"] } });
    audio.callback?.("AA==", 0.01);
    expect(socket.sent).toContainEqual({ realtimeInput: { audio: { mimeType: "audio/pcm;rate=16000", data: "AA==" } } });

    socket.receive({
      serverContent: {
        inputTranscription: { text: "Ciao" },
        outputTranscription: { text: "Come posso aiutarti?" },
        interrupted: true,
      },
      toolCall: { functionCalls: [{ id: "call_1", name: "runtime_state_get", args: {} }] },
      usageMetadata: {
        promptTokensDetails: [{ modality: "AUDIO", tokenCount: 25 }],
        responseTokensDetails: [{ modality: "TEXT", tokenCount: 12 }],
      },
    });
    await driver.submitToolResult({ call_id: "call_1", status: "completed", state_revision: 5, output: {} });
    await driver.disconnect();

    expect(audio.flushed).toBe(true);
    expect(events).toEqual(expect.arrayContaining([
      { type: "agent.connected" },
      expect.objectContaining({ type: "agent.transcript.final", role: "user", text: "Ciao" }),
      expect.objectContaining({ type: "agent.transcript.final", role: "agent", text: "Come posso aiutarti?" }),
      expect.objectContaining({ type: "agent.tool.call", call: expect.objectContaining({ name: "runtime.state.get", id: "call_1" }) }),
      expect.objectContaining({ type: "agent.usage", usage: expect.objectContaining({ units: { input_audio_tokens: 25, output_text_tokens: 12 } }) }),
    ]));
    expect(socket.sent).toContainEqual(expect.objectContaining({
      toolResponse: { functionResponses: [expect.objectContaining({ id: "call_1", name: "runtime_state_get" })] },
    }));
  });

  it("normalizes modality token details without treating them as duration", () => {
    expect(normalizeGeminiUsage({
      promptTokensDetails: [{ modality: "TEXT", tokenCount: 10 }, { modality: "AUDIO", tokenCount: 20 }],
      responseTokensDetails: [{ modality: "AUDIO", tokenCount: 5 }],
    }, "turn-1", "gemini-3.8-live")).toMatchObject({
      idempotency_key: "gemini-live-usage:turn-1",
      units: { input_text_tokens: 10, input_audio_tokens: 20, output_audio_tokens: 5 },
    });
  });
});
