export class EventStream {
    listeners = new Set();
    on(listener) {
        this.listeners.add(listener);
        return () => this.listeners.delete(listener);
    }
    emit(event) {
        for (const listener of this.listeners) {
            listener(event);
        }
    }
    clear() {
        this.listeners.clear();
    }
}
