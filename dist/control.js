export class RealtimeControlError extends Error {
    status;
    code;
    state;
    constructor(message, status, code, state) {
        super(message);
        this.status = status;
        this.code = code;
        this.state = state;
    }
}
export class LaravelControlTransport {
    sessionId;
    baseUrl;
    request;
    constructor(sessionId, baseUrl = "/realtime-agent", request = globalThis.fetch.bind(globalThis)) {
        this.sessionId = sessionId;
        this.baseUrl = baseUrl;
        this.request = request;
    }
    async executeTool(input) {
        const body = await this.json("/tools", {
            method: "POST",
            body: {
                ...input,
                idempotency_key: input.idempotency_key ?? input.id,
                provider_call_id: input.provider_call_id ?? input.id,
            },
        });
        return body.result;
    }
    async refreshState() {
        return (await this.json("", { method: "GET" })).state;
    }
    async syncSurface(baseRevision, snapshot) {
        return (await this.json("/surface", {
            method: "PUT",
            body: { base_revision: baseRevision, snapshot },
        })).state;
    }
    async resolveConfirmation(id, accepted) {
        return (await this.json(`/confirmations/${encodeURIComponent(id)}`, {
            method: "POST",
            body: { accepted },
        })).result;
    }
    async completeUiCommand(command, baseRevision, result) {
        return (await this.json(`/commands/${encodeURIComponent(command.id)}`, {
            method: "POST",
            body: {
                base_revision: baseRevision,
                token: command.token,
                result: this.asObject(result),
                surface: result.surface,
            },
        })).state;
    }
    async finish(baseRevision) {
        return (await this.json("", {
            method: "DELETE",
            body: { base_revision: baseRevision },
        })).state;
    }
    async recordMessage(input) {
        return (await this.json("/messages", {
            method: "POST",
            body: input,
        })).message;
    }
    async recordUsage(input) {
        return (await this.json("/usage", {
            method: "POST",
            body: input,
        })).usage;
    }
    async fetchAudit() {
        return (await this.json("/audit", { method: "GET" })).audit;
    }
    async json(path, options) {
        const response = await this.request(`${this.baseUrl.replace(/\/$/, "")}/sessions/${encodeURIComponent(this.sessionId)}${path}`, {
            method: options.method,
            credentials: "same-origin",
            headers: {
                Accept: "application/json",
                "Content-Type": "application/json",
                "X-CSRF-TOKEN": this.csrfToken(),
            },
            body: options.body ? JSON.stringify(options.body) : undefined,
        });
        const payload = await response.json();
        if (!response.ok) {
            const error = (payload.error ?? {});
            throw new RealtimeControlError(String(error.message ?? "Realtime control request failed."), response.status, String(error.code ?? "request_failed"), payload.state);
        }
        return payload;
    }
    csrfToken() {
        return globalThis.document?.querySelector('meta[name="csrf-token"]')?.content ?? "";
    }
    asObject(result) {
        return JSON.parse(JSON.stringify(result));
    }
}
