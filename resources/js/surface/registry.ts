import type {
  JsonObject,
  SurfaceDefinition,
  SurfaceSnapshot,
} from "../types.js";
import { EventStream } from "../events.js";

export class SurfaceRegistry {
  private readonly surfaces = new Map<string, SurfaceDefinition>();
  private activeSurface: string | null = null;
  private revision = 0;
  private readonly changes = new EventStream<string>();

  register(definition: SurfaceDefinition): () => void {
    if (this.surfaces.has(definition.id)) {
      throw new Error(`Surface ${definition.id} is already registered.`);
    }

    this.surfaces.set(definition.id, definition);
    this.activeSurface = definition.id;
    this.notify(definition.id);

    return () => {
      this.surfaces.delete(definition.id);

      if (this.activeSurface === definition.id) {
        this.activeSurface = null;
      }
    };
  }

  onChange(listener: (surface: string) => void): () => void {
    return this.changes.on(listener);
  }

  notify(id = this.activeSurface): void {
    if (id !== null && this.surfaces.has(id)) this.changes.emit(id);
  }

  has(id: string): boolean {
    return this.surfaces.has(id);
  }

  currentId(): string | null {
    return this.activeSurface;
  }

  snapshot(id = this.activeSurface): SurfaceSnapshot {
    if (id === null) {
      throw new Error("No active Surface is registered.");
    }

    const definition = this.surfaces.get(id);

    if (!definition) {
      throw new Error(`Unknown Surface: ${id}.`);
    }

    const snapshot = definition.snapshot();

    if ("surface" in snapshot && snapshot.surface !== id) {
      throw new Error(`Surface snapshot id does not match ${id}.`);
    }

    return {
      ...snapshot,
      surface: id,
      revision: ++this.revision,
    };
  }

  async execute(
    surface: string,
    action: string,
    target: string,
    arguments_: JsonObject = {},
  ): Promise<{ output: unknown; surface: SurfaceSnapshot }> {
    const definition = this.surfaces.get(surface);

    if (!definition) {
      throw new Error(`Unknown Surface: ${surface}.`);
    }

    const before = this.snapshot(surface);
    const component = before.components[target];

    if (!component) {
      throw new Error(`Unknown semantic target: ${target}.`);
    }

    if (!component.actions.includes(action)) {
      throw new Error(`Action ${action} is not exposed by ${target}.`);
    }

    const handler = definition.actions[action];

    if (!handler) {
      throw new Error(`No registered handler for action ${action}.`);
    }

    const output = await handler({ target, ...arguments_ });

    return { output, surface: this.snapshot(surface) };
  }
}
