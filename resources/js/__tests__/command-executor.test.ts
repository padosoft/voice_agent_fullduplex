import { describe, expect, it, vi } from "vitest";
import { SurfaceRegistry } from "../surface/registry.js";
import { UiCommandExecutor } from "../surface/command-executor.js";
import type { UiCommand } from "../types.js";

function command(overrides: Partial<UiCommand> = {}): UiCommand {
  return {
    id: "cmd_1",
    session_id: "session_1",
    call_id: "call_1",
    base_revision: 1,
    surface: "lesson.show",
    action: "highlight",
    target: "lesson.topic_1",
    arguments: {},
    expires_at: "2026-09-10T12:01:00.000Z",
    nonce: "nonce_1",
    token: "a".repeat(64),
    ...overrides,
  };
}

describe("UiCommandExecutor", () => {
  it("executes only registered semantic actions and returns a fresh snapshot", async () => {
    const highlight = vi.fn();
    const registry = new SurfaceRegistry();
    registry.register({
      id: "lesson.show",
      snapshot: () => ({
        title: "Lesson",
        components: {
          "lesson.topic_1": {
            type: "goal",
            label: "Topic 1",
            state: { completed: false },
            actions: ["highlight"],
          },
        },
      }),
      actions: { highlight },
    });

    const executor = new UiCommandExecutor(
      "session_1",
      registry,
      () => new Date("2026-09-10T12:00:00.000Z"),
    );
    const result = await executor.execute(command());

    expect(result.status).toBe("completed");
    expect(result.surface?.surface).toBe("lesson.show");
    expect(highlight).toHaveBeenCalledWith({ target: "lesson.topic_1" });
  });

  it("rejects expired, replayed, and unregistered commands", async () => {
    const registry = new SurfaceRegistry();
    registry.register({
      id: "lesson.show",
      snapshot: () => ({
        title: "Lesson",
        components: {
          "lesson.topic_1": {
            type: "goal",
            label: "Topic 1",
            state: {},
            actions: ["highlight"],
          },
        },
      }),
      actions: { highlight: () => undefined },
    });
    const executor = new UiCommandExecutor(
      "session_1",
      registry,
      () => new Date("2026-09-10T12:00:00.000Z"),
    );

    expect((await executor.execute(command({ expires_at: "2026-09-10T11:59:00.000Z" }))).status)
      .toBe("failed");
    expect((await executor.execute(command())).status).toBe("completed");
    expect((await executor.execute(command())).error?.message).toContain("already used");
    expect((await executor.execute(command({ id: "cmd_2", nonce: "nonce_2", target: "#raw-css" }))).status)
      .toBe("failed");
  });
});
