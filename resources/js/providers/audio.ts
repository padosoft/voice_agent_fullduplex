export interface RealtimeAudioBridge {
  start(onChunk: (base64Pcm: string, seconds: number) => void): Promise<void>;
  stop(): void;
  setMuted(muted: boolean): void;
  play(base64Pcm: string, sampleRate: number): number;
  flush(): void;
}

const bytesToBase64 = (bytes: Uint8Array): string => {
  let binary = "";
  bytes.forEach((byte) => { binary += String.fromCharCode(byte); });

  return globalThis.btoa(binary);
};

const base64ToBytes = (value: string): Uint8Array => {
  const binary = globalThis.atob(value);
  const bytes = new Uint8Array(binary.length);

  for (let index = 0; index < binary.length; index += 1) bytes[index] = binary.charCodeAt(index);

  return bytes;
};

const resample = (input: Float32Array, sourceRate: number, targetRate: number): Float32Array => {
  if (sourceRate === targetRate) return input;

  const outputLength = Math.max(1, Math.round(input.length * targetRate / sourceRate));
  const output = new Float32Array(outputLength);
  const ratio = sourceRate / targetRate;

  for (let outputIndex = 0; outputIndex < outputLength; outputIndex += 1) {
    const start = Math.floor(outputIndex * ratio);
    const end = Math.min(input.length, Math.max(start + 1, Math.floor((outputIndex + 1) * ratio)));
    let sum = 0;

    for (let index = start; index < end; index += 1) sum += input[index] ?? 0;

    output[outputIndex] = sum / (end - start);
  }

  return output;
};

const pcm16 = (input: Float32Array): Uint8Array => {
  const result = new Uint8Array(input.length * 2);
  const view = new DataView(result.buffer);

  input.forEach((sample, index) => view.setInt16(index * 2, Math.round(Math.max(-1, Math.min(1, sample)) * 0x7fff), true));

  return result;
};

/**
 * Browser-only microphone and PCM playback bridge. Provider drivers pass raw
 * PCM through it so credentials and provider protocols stay separate from media.
 */
export class BrowserRealtimeAudioBridge implements RealtimeAudioBridge {
  private context: AudioContext | null = null;
  private stream: MediaStream | null = null;
  private processor: ScriptProcessorNode | null = null;
  private source: MediaStreamAudioSourceNode | null = null;
  private muted = false;
  private nextPlaybackAt = 0;
  private readonly playing = new Set<AudioBufferSourceNode>();

  constructor(
    private readonly mediaDevices: MediaDevices | undefined = globalThis.navigator?.mediaDevices,
    private readonly contextFactory: () => AudioContext = () => new AudioContext(),
    private readonly targetSampleRate = 16_000,
  ) {}

  async start(onChunk: (base64Pcm: string, seconds: number) => void): Promise<void> {
    if (this.stream) return;
    if (!this.mediaDevices?.getUserMedia) throw new Error("Microphone access is unavailable in this browser.");

    this.stream = await this.mediaDevices.getUserMedia({ audio: true });
    this.context = this.contextFactory();
    await this.context.resume();
    this.source = this.context.createMediaStreamSource(this.stream);
    this.processor = this.context.createScriptProcessor(2048, 1, 1);
    this.processor.onaudioprocess = (event) => {
      if (this.muted || !this.context) return;

      const samples = resample(event.inputBuffer.getChannelData(0), this.context.sampleRate, this.targetSampleRate);
      onChunk(bytesToBase64(pcm16(samples)), samples.length / this.targetSampleRate);
    };
    this.source.connect(this.processor);
    this.processor.connect(this.context.destination);
  }

  stop(): void {
    this.processor?.disconnect();
    this.source?.disconnect();
    this.stream?.getTracks().forEach((track) => track.stop());
    void this.context?.close();
    this.processor = null;
    this.source = null;
    this.stream = null;
    this.context = null;
    this.flush();
  }

  setMuted(muted: boolean): void {
    this.muted = muted;
  }

  play(base64Pcm: string, sampleRate: number): number {
    if (this.muted || !this.context || !base64Pcm) return 0;

    const bytes = base64ToBytes(base64Pcm);
    const frames = Math.floor(bytes.byteLength / 2);

    if (frames === 0) return 0;

    const values = new Float32Array(frames);
    const view = new DataView(bytes.buffer, bytes.byteOffset, bytes.byteLength);

    for (let index = 0; index < frames; index += 1) values[index] = view.getInt16(index * 2, true) / 0x8000;

    const buffer = this.context.createBuffer(1, frames, sampleRate);
    buffer.copyToChannel(values, 0);
    const source = this.context.createBufferSource();
    source.buffer = buffer;
    source.connect(this.context.destination);
    const startedAt = Math.max(this.context.currentTime, this.nextPlaybackAt);
    source.start(startedAt);
    this.nextPlaybackAt = startedAt + buffer.duration;
    this.playing.add(source);
    source.addEventListener("ended", () => this.playing.delete(source), { once: true });

    return buffer.duration;
  }

  flush(): void {
    this.playing.forEach((source) => source.stop());
    this.playing.clear();
    this.nextPlaybackAt = this.context?.currentTime ?? 0;
  }
}
