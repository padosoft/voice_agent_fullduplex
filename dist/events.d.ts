export declare class EventStream<T> {
    private readonly listeners;
    on(listener: (event: T) => void): () => void;
    emit(event: T): void;
    clear(): void;
}
