import { SurfaceRegistry } from "./registry.js";
export declare class DomSurfaceAdapter {
    private readonly root;
    private readonly registry;
    constructor(root: ParentNode, registry: SurfaceRegistry);
    register(id: string, title?: string | null): () => void;
    private components;
    private element;
    private setValue;
    private stateOf;
    private typeOf;
}
