import type { UiCommand, UiCommandResult } from "../types.js";
import { SurfaceRegistry } from "./registry.js";

export class UiCommandExecutor {
  private readonly usedNonces = new Set<string>();

  constructor(
    private readonly sessionId: string,
    private readonly surfaces: SurfaceRegistry,
    private readonly now: () => Date = () => new Date(),
  ) {}

  async execute(command: UiCommand): Promise<UiCommandResult> {
    try {
      this.validate(command);
      this.usedNonces.add(command.nonce);

      const result = await this.surfaces.execute(
        command.surface,
        command.action,
        command.target,
        command.arguments,
      );

      return {
        command_id: command.id,
        status: "completed",
        output: result.output,
        surface: result.surface,
      };
    } catch (error) {
      return {
        command_id: command.id,
        status: "failed",
        error: {
          code: "ui_command_rejected",
          message: error instanceof Error ? error.message : "UI command failed.",
        },
      };
    }
  }

  private validate(command: UiCommand): void {
    if (command.session_id !== this.sessionId) {
      throw new Error("Command belongs to another session.");
    }

    if (new Date(command.expires_at).getTime() <= this.now().getTime()) {
      throw new Error("Command has expired.");
    }

    if (this.usedNonces.has(command.nonce)) {
      throw new Error("Command nonce was already used.");
    }

    if (!this.surfaces.has(command.surface)) {
      throw new Error(`Unknown Surface: ${command.surface}.`);
    }
  }
}
