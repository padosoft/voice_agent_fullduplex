import type { JsonObject } from "../types.js";
export interface SemanticPatch {
    path: string;
    value?: unknown;
    operation: "add" | "replace" | "remove";
}
export declare function semanticDiff(before: JsonObject | undefined, after: JsonObject): SemanticPatch[];
