import { JSDOM } from "jsdom";
import { describe, expect, it, vi } from "vitest";
import { DomSurfaceAdapter } from "../surface/dom-adapter.js";
import { SurfaceRegistry } from "../surface/registry.js";

describe("DomSurfaceAdapter", () => {
  it("exposes annotated fields without leaking the rest of the DOM", async () => {
    const dom = new JSDOM(`
      <input data-agent-id="customer.email"
             data-agent-label="Customer email"
             data-agent-actions="focus,set_value"
             value="old@example.com">
      <input id="secret" value="never expose me">
    `);
    const input = dom.window.document.querySelector<HTMLInputElement>("[data-agent-id]")!;
    const changed = vi.fn();
    input.addEventListener("change", changed);

    const registry = new SurfaceRegistry();
    new DomSurfaceAdapter(dom.window.document, registry).register("customers.edit", "Edit customer");

    const snapshot = registry.snapshot();
    expect(Object.keys(snapshot.components)).toEqual(["customer.email"]);

    const result = await registry.execute(
      "customers.edit",
      "set_value",
      "customer.email",
      { value: "new@example.com" },
    );

    expect(input.value).toBe("new@example.com");
    expect(changed).toHaveBeenCalledOnce();
    expect(result.surface.components["customer.email"].state.value).toBe("new@example.com");
  });
});
