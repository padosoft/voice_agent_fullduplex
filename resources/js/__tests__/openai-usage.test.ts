import { describe, expect, it } from "vitest";
import {
  normalizeOpenAILiveDurationUsage,
  normalizeOpenAIResponseUsage,
  OpenAILiveTranscriptAssembler,
  openAIResponseCreateEvent,
} from "../providers/openai.js";

describe("OpenAI GPT-Live normalization", () => {
  it("normalizes delegated Responses token usage", () => {
    expect(normalizeOpenAIResponseUsage({
      input_tokens: 132,
      output_tokens: 121,
      input_tokens_details: { cached_tokens: 64 },
      output_tokens_details: { reasoning_tokens: 18 },
    })).toEqual({
      input_text_tokens: 132,
      cached_input_text_tokens: 64,
      input_audio_tokens: 0,
      cached_input_audio_tokens: 0,
      input_image_tokens: 0,
      cached_input_image_tokens: 0,
      output_text_tokens: 121,
      output_audio_tokens: 0,
    });
  });

  it("stores cumulative duration snapshots under one idempotency key", () => {
    const update = normalizeOpenAILiveDurationUsage({
      type: "session.usage.updated",
      event_id: "event_usage_1",
      usage: { seconds: 12 },
      context_window: { usage_ratio: 0.42 },
    }, "live_123");
    const closed = normalizeOpenAILiveDurationUsage({
      type: "session.closed",
      event_id: "event_closed_1",
      usage: { seconds: 19 },
      reason: "close_requested",
    }, "live_123");

    expect(update).toMatchObject({
      idempotency_key: "openai-live-duration:live_123",
      kind: "duration",
      model: "gpt-live-1",
      units: { duration_seconds: 12 },
      raw: { cumulative_snapshot: true, final: false },
    });
    expect(closed).toMatchObject({
      idempotency_key: "openai-live-duration:live_123",
      units: { duration_seconds: 19 },
      raw: { reason: "close_requested", final: true },
    });
  });

  it("uses the bodyless Live response.create command in either interaction mode", () => {
    expect(openAIResponseCreateEvent("text")).toEqual({ type: "response.create" });
    expect(openAIResponseCreateEvent("voice", "continue_1")).toEqual({
      type: "response.create",
      event_id: "continue_1",
    });
  });

  it("groups timestamped transcript fragments and preserves late corrections", () => {
    const transcript = new OpenAILiveTranscriptAssembler(500);
    const first = transcript.push("user", {
      event_id: "fragment_2",
      delta: "world",
      start_ms: 200,
      end_ms: 400,
    });
    transcript.push("user", {
      event_id: "fragment_1",
      delta: "Hello ",
      start_ms: 0,
      end_ms: 200,
    });

    const completed = transcript.finalize(first!.rowId);

    expect(completed).toMatchObject({
      role: "user",
      text: "Hello world",
      status: "completed",
      metadata: {
        start_ms: 0,
        end_ms: 400,
        fragments: [
          { provider_event_id: "fragment_1", delta: "Hello ", start_ms: 0, end_ms: 200 },
          { provider_event_id: "fragment_2", delta: "world", start_ms: 200, end_ms: 400 },
        ],
      },
    });

    const late = transcript.push("user", {
      event_id: "fragment_late",
      delta: "there ",
      start_ms: 180,
      end_ms: 200,
    });

    expect(late?.correction).toMatchObject({
      messageId: completed?.messageId,
      text: "Hello there world",
      status: "corrected",
    });
  });
});
