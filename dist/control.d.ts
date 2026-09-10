import type { AgentState, ControlTransport, SurfaceSnapshot, ToolCallInput, ToolResult, UiCommand, UiCommandResult } from "./types.js";
export declare class RealtimeControlError extends Error {
    readonly status: number;
    readonly code: string;
    readonly state?: AgentState | undefined;
    constructor(message: string, status: number, code: string, state?: AgentState | undefined);
}
export declare class LaravelControlTransport implements ControlTransport {
    private readonly sessionId;
    private readonly baseUrl;
    private readonly request;
    constructor(sessionId: string, baseUrl?: string, request?: typeof fetch);
    executeTool(input: ToolCallInput): Promise<ToolResult>;
    refreshState(): Promise<AgentState>;
    syncSurface(baseRevision: number, snapshot: SurfaceSnapshot): Promise<AgentState>;
    resolveConfirmation(id: string, accepted: boolean): Promise<ToolResult>;
    completeUiCommand(command: UiCommand, baseRevision: number, result: UiCommandResult): Promise<AgentState>;
    finish(baseRevision: number): Promise<AgentState>;
    private json;
    private csrfToken;
    private asObject;
}
