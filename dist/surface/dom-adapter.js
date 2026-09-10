const selector = "[data-agent-id]";
export class DomSurfaceAdapter {
    root;
    registry;
    constructor(root, registry) {
        this.root = root;
        this.registry = registry;
    }
    register(id, title = null) {
        const unregister = this.registry.register({
            id,
            snapshot: () => ({
                title,
                components: this.components(),
            }),
            actions: {
                focus: ({ target }) => this.element(target).focus(),
                set_value: (context) => this.setValue(context),
                activate: ({ target }) => this.element(target).click(),
                highlight: ({ target }) => {
                    this.element(target).setAttribute("data-agent-highlighted", "true");
                },
            },
        });
        const notify = () => this.registry.notify(id);
        const ownerDocument = "ownerDocument" in this.root ? this.root.ownerDocument : null;
        const document = ownerDocument ?? this.root;
        const view = document?.defaultView;
        const observer = view ? new view.MutationObserver(notify) : null;
        observer?.observe(this.root, { subtree: true, childList: true, attributes: true, characterData: true });
        this.root.addEventListener("input", notify);
        this.root.addEventListener("change", notify);
        return () => {
            observer?.disconnect();
            this.root.removeEventListener("input", notify);
            this.root.removeEventListener("change", notify);
            unregister();
        };
    }
    components() {
        const components = {};
        for (const element of this.root.querySelectorAll(selector)) {
            const id = element.dataset.agentId;
            if (!id)
                continue;
            components[id] = {
                type: this.typeOf(element),
                label: element.dataset.agentLabel ?? element.getAttribute("aria-label") ?? id,
                state: this.stateOf(element),
                actions: (element.dataset.agentActions ?? "")
                    .split(",")
                    .map((action) => action.trim())
                    .filter(Boolean),
            };
        }
        return components;
    }
    element(id) {
        for (const candidate of this.root.querySelectorAll(selector)) {
            if (candidate.dataset.agentId === id)
                return candidate;
        }
        throw new Error(`Unknown semantic target: ${id}.`);
    }
    setValue({ target, value }) {
        const element = this.element(target);
        if (!(element instanceof element.ownerDocument.defaultView.HTMLInputElement)) {
            throw new Error(`Target ${target} does not accept set_value.`);
        }
        element.value = String(value ?? "");
        element.dispatchEvent(new element.ownerDocument.defaultView.Event("input", { bubbles: true }));
        element.dispatchEvent(new element.ownerDocument.defaultView.Event("change", { bubbles: true }));
    }
    stateOf(element) {
        const view = element.ownerDocument.defaultView;
        if (view && element instanceof view.HTMLInputElement) {
            return {
                value: element.value,
                disabled: element.disabled,
                checked: element.checked,
            };
        }
        if (view && element instanceof view.HTMLButtonElement) {
            return { enabled: !element.disabled };
        }
        return { text: element.textContent?.trim() ?? "" };
    }
    typeOf(element) {
        const view = element.ownerDocument.defaultView;
        if (view && element instanceof view.HTMLInputElement) {
            return element.type || "text";
        }
        return element.tagName.toLowerCase();
    }
}
