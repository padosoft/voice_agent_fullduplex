import type { JsonObject, SurfaceDefinition, SurfaceSnapshot } from "../types.js";
export declare class SurfaceRegistry {
    private readonly surfaces;
    private activeSurface;
    private revision;
    private readonly changes;
    register(definition: SurfaceDefinition): () => void;
    onChange(listener: (surface: string) => void): () => void;
    notify(id?: string | null): void;
    has(id: string): boolean;
    currentId(): string | null;
    snapshot(id?: string | null): SurfaceSnapshot;
    execute(surface: string, action: string, target: string, arguments_?: JsonObject): Promise<{
        output: unknown;
        surface: SurfaceSnapshot;
    }>;
}
