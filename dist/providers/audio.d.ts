export interface RealtimeAudioBridge {
    start(onChunk: (base64Pcm: string, seconds: number) => void): Promise<void>;
    stop(): void;
    setMuted(muted: boolean): void;
    play(base64Pcm: string, sampleRate: number): number;
    flush(): void;
}
/**
 * Browser-only microphone and PCM playback bridge. Provider drivers pass raw
 * PCM through it so credentials and provider protocols stay separate from media.
 */
export declare class BrowserRealtimeAudioBridge implements RealtimeAudioBridge {
    private readonly mediaDevices;
    private readonly contextFactory;
    private readonly targetSampleRate;
    private context;
    private stream;
    private processor;
    private source;
    private muted;
    private nextPlaybackAt;
    private readonly playing;
    constructor(mediaDevices?: MediaDevices | undefined, contextFactory?: () => AudioContext, targetSampleRate?: number);
    start(onChunk: (base64Pcm: string, seconds: number) => void): Promise<void>;
    stop(): void;
    setMuted(muted: boolean): void;
    play(base64Pcm: string, sampleRate: number): number;
    flush(): void;
}
