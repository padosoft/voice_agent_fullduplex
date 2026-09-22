import { describe, expect, it, vi } from "vitest";
import { XaiVoiceDriver, xaiAudioUsage } from "../providers/xai.js";
import type { AgentState, CanonicalProviderEvent, ConnectionDescriptor } from "../types.js";
import type { RealtimeAudioBridge } from "../providers/audio.js";

class FakeSocket extends EventTarget {
  readyState = WebSocket.CONNECTING;
  readonly sent: Record<string, unknown>[] = [];
  protocols: string | string[] | undefined;

  open(): void { this.readyState = WebSocket.OPEN; this.dispatchEvent(new Event("open")); }
  receive(value: Record<string, unknown>): void { this.dispatchEvent(new MessageEvent("message", { data: JSON.stringify(value) })); }
  send(payload: string): void { this.sent.push(JSON.parse(payload) as Record<string, unknown>); }
  close(): void { this.readyState = WebSocket.CLOSED; this.dispatchEvent(new Event("close")); }
}

class FakeAudio implements RealtimeAudioBridge {
  callback: ((audio: string, seconds: number) => void) | null = null;
  flushed = false;
  async start(callback: (audio: string, seconds: number) => void): Promise<void> { this.callback = callback; }
  stop(): void {}
  setMuted(): void {}
  play(): number { return 0.5; }
  flush(): void { this.flushed = true; }
}

const state: AgentState = {
  schema: "realtime-agent-state@1",
  session: { id: "xai-session", revision: 3, status: "active" },
  pending: { actions: [], confirmations: [] },
};

const descriptor: ConnectionDescriptor = {
  session_id: "xai-session",
  provider: "xai",
  connection: { transport: "websocket", bootstrap_url: "/realtime-agent/sessions/xai-session/connect" },
  state,
};

describe("xAI Voice browser driver", () => {
  it("uses a client-secret protocol and audits PCM, transcripts and custom functions", async () => {
    const socket = new FakeSocket();
    const audio = new FakeAudio();
    const request = vi.fn(async () => new Response(JSON.stringify({
      ...descriptor,
      connection: {
        transport: "websocket",
        endpoint: "wss://api.x.ai/v1/realtime",
        client_secret: "short-secret",
        model: "grok-voice-latest",
        session: { voice: "eve", tools: [] },
        tool_name_map: { runtime_state_get: "runtime.state.get" },
        history: [],
      },
    }), { headers: { "Content-Type": "application/json" } }));
    const factory = vi.fn((_: string, protocols?: string | string[]) => {
      socket.protocols = protocols;
      return socket as unknown as WebSocket;
    });
    const driver = new XaiVoiceDriver(request as unknown as typeof fetch, factory, audio);
    const events: CanonicalProviderEvent[] = [];
    driver.on((event) => events.push(event));
    const connecting = driver.connect(descriptor);
    await vi.waitFor(() => expect(factory).toHaveBeenCalledOnce());
    socket.open();
    await Promise.resolve();
    socket.receive({ type: "session.updated" });
    await connecting;

    expect(socket.protocols).toEqual(["xai-client-secret.short-secret"]);
    expect(socket.sent).toContainEqual({ type: "session.update", session: { voice: "eve", tools: [] } });
    audio.callback?.("AA==", 0.25);
    socket.receive({ type: "input_audio_buffer.speech_started" });
    socket.receive({ type: "response.output_audio.delta", delta: btoa("\0".repeat(24_000)) });
    socket.receive({ type: "response.output_audio_transcript.done", item_id: "agent_1", transcript: "Pronto." });
    socket.receive({ type: "conversation.item.input_audio_transcription.completed", item_id: "user_1", transcript: "Ciao" });
    socket.receive({ type: "response.output_item.done", item: { type: "function_call", call_id: "call_1", name: "runtime_state_get", arguments: "{}" } });
    await driver.sendText("Scriviamo");
    await driver.disconnect();

    expect(audio.flushed).toBe(true);
    expect(events).toEqual(expect.arrayContaining([
      expect.objectContaining({ type: "agent.usage", usage: expect.objectContaining({ units: { input_audio_seconds: 0.25 } }) }),
      expect.objectContaining({ type: "agent.usage", usage: expect.objectContaining({ units: { output_audio_seconds: 0.5 } }) }),
      expect.objectContaining({ type: "agent.transcript.final", role: "user", text: "Ciao" }),
      expect.objectContaining({ type: "agent.transcript.final", role: "agent", text: "Pronto." }),
      expect.objectContaining({ type: "agent.tool.call", call: expect.objectContaining({ name: "runtime.state.get" }) }),
    ]));
    expect(socket.sent).toContainEqual(expect.objectContaining({ type: "conversation.item.create" }));
    expect(socket.sent).toContainEqual({ type: "response.create" });
  });

  it("uses separate audit units for direction and preserves per-minute pricing inputs", () => {
    expect(xaiAudioUsage("output-1", "grok-voice-latest", "output", 12.5)).toMatchObject({
      idempotency_key: "xai-voice-output-1",
      units: { output_audio_seconds: 12.5 },
    });
  });
});
