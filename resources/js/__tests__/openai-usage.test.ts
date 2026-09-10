import { describe, expect, it } from "vitest";
import { normalizeOpenAIResponseUsage, openAIResponseCreateEvent } from "../providers/openai.js";

describe("OpenAI Realtime usage", () => {
  it("normalizes the documented response.done modality and cache breakdown", () => {
    expect(normalizeOpenAIResponseUsage({
      total_tokens: 253,
      input_tokens: 132,
      output_tokens: 121,
      input_token_details: {
        text_tokens: 119,
        audio_tokens: 13,
        image_tokens: 0,
        cached_tokens: 64,
        cached_tokens_details: { text_tokens: 64, audio_tokens: 0, image_tokens: 0 },
      },
      output_token_details: { text_tokens: 30, audio_tokens: 91 },
    })).toEqual({
      input_text_tokens: 119,
      cached_input_text_tokens: 64,
      input_audio_tokens: 13,
      cached_input_audio_tokens: 0,
      input_image_tokens: 0,
      cached_input_image_tokens: 0,
      output_text_tokens: 30,
      output_audio_tokens: 91,
    });
  });

  it("requests text-only responses after the voice connection switches mode", () => {
    expect(openAIResponseCreateEvent("text")).toEqual({
      type: "response.create",
      response: { output_modalities: ["text"] },
    });
    expect(openAIResponseCreateEvent("voice")).toEqual({ type: "response.create" });
  });
});
