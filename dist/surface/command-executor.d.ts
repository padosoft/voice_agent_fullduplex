import type { UiCommand, UiCommandResult } from "../types.js";
import { SurfaceRegistry } from "./registry.js";
export declare class UiCommandExecutor {
    private readonly sessionId;
    private readonly surfaces;
    private readonly now;
    private readonly usedNonces;
    constructor(sessionId: string, surfaces: SurfaceRegistry, now?: () => Date);
    execute(command: UiCommand): Promise<UiCommandResult>;
    private validate;
}
