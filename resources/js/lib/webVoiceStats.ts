/**
 * Pure parsing of WebRTC stats for the connection diagnostics of the web voice page.
 *
 * Everything here is plain functions over plain objects (no DOM, no livekit-client), so it is
 * type-checked, can be fed a captured report, and the Vue layer stays thin. Field names follow
 * the W3C WebRTC statistics (webrtc-stats): `RTCIceCandidatePairStats.currentRoundTripTime`,
 * `RTCInboundRtpStreamStats.{jitter, jitterBufferDelay, jitterBufferEmittedCount, packetsLost,
 * concealedSamples}` and `RTCOutboundRtpStreamStats`.
 *
 * Counters in a report are cumulative since the stream started, so every rate (bitrate, loss,
 * average jitter-buffer delay, concealment) is computed from the difference of two samples, never
 * from the raw totals. Values the browser does not report stay `null`; nothing is invented.
 */

export type StatsRecord = { id: string; type: string; [key: string]: unknown };

export type CandidateType = 'host' | 'srflx' | 'prflx' | 'relay';
export type TransportProtocol = 'udp' | 'tcp';
export type PathDirection = 'send' | 'receive';

export type PathInfo = {
    direction: PathDirection;
    localType: CandidateType | null;
    remoteType: CandidateType | null;
    protocol: TransportProtocol | null;
    /** Round-trip time of the selected candidate pair in ms (STUN/ICE consent checks). */
    rttMs: number | null;
};

/** Cumulative counters of one peer connection at one moment. */
export type RawSample = {
    direction: PathDirection;
    /** `timestamp` of the sampled stat in ms (browser clock). */
    at: number;
    path: PathInfo;
    bytesSent: number | null;
    packetsSent: number | null;
    bytesReceived: number | null;
    packetsReceived: number | null;
    packetsLost: number | null;
    jitterSeconds: number | null;
    jitterBufferDelay: number | null;
    jitterBufferEmittedCount: number | null;
    concealedSamples: number | null;
    totalSamplesReceived: number | null;
    totalPlayoutDelay: number | null;
    totalPlayoutSamples: number | null;
    codec: string | null;
    fmtp: string | null;
};

export type ConnectionMetrics = {
    direction: PathDirection;
    path: PathInfo;
    /** Kilobits per second over the last interval, `null` until two samples exist. */
    kbps: number | null;
    jitterMs: number | null;
    /** Mean delay a sample spent in the jitter buffer over the last interval. */
    jitterBufferMs: number | null;
    /** Packets lost / packets expected over the last interval, 0..100. */
    lossPercent: number | null;
    /** Share of samples that were synthesized to hide loss or underrun, 0..100. */
    concealedPercent: number | null;
    /** Mean delay from the buffer to the audio device (media-playout stats), when reported. */
    playoutMs: number | null;
    codec: string | null;
    /** `ptime=` from the codec's fmtp line when negotiated, else `null`. */
    packetTimeMs: number | null;
};

const CANDIDATE_TYPES: readonly string[] = ['host', 'srflx', 'prflx', 'relay'];

function num(value: unknown): number | null {
    return typeof value === 'number' && Number.isFinite(value) ? value : null;
}

function str(value: unknown): string | null {
    return typeof value === 'string' && value !== '' ? value : null;
}

function candidateType(record: StatsRecord | undefined): CandidateType | null {
    const type = record ? str(record.candidateType) : null;

    return type !== null && CANDIDATE_TYPES.includes(type)
        ? (type as CandidateType)
        : null;
}

function protocolOf(record: StatsRecord | undefined): TransportProtocol | null {
    const protocol = record ? str(record.protocol) : null;

    return protocol === 'udp' || protocol === 'tcp' ? protocol : null;
}

/**
 * The candidate pair that carries the media. Chrome and Safari name it in
 * `transport.selectedCandidatePairId`; Firefox does not, so fall back to a pair that is both
 * `nominated` and `succeeded` (or `selected`, which older Firefox versions set).
 */
export function selectedCandidatePair(
    records: StatsRecord[],
): StatsRecord | undefined {
    const byId = new Map(records.map((record) => [record.id, record]));

    for (const record of records) {
        if (record.type === 'transport') {
            const id = str(record.selectedCandidatePairId);
            const pair = id !== null ? byId.get(id) : undefined;

            if (pair) {
                return pair;
            }
        }
    }

    const pairs = records.filter((record) => record.type === 'candidate-pair');

    return (
        pairs.find((pair) => pair.selected === true) ??
        pairs.find(
            (pair) => pair.nominated === true && pair.state === 'succeeded',
        )
    );
}

export function parsePath(
    records: StatsRecord[],
    direction: PathDirection,
): PathInfo {
    const byId = new Map(records.map((record) => [record.id, record]));
    const pair = selectedCandidatePair(records);
    const local = pair ? byId.get(str(pair.localCandidateId) ?? '') : undefined;
    const remote = pair
        ? byId.get(str(pair.remoteCandidateId) ?? '')
        : undefined;
    const rtt = pair ? num(pair.currentRoundTripTime) : null;

    return {
        direction,
        localType: candidateType(local),
        remoteType: candidateType(remote),
        // The local candidate's protocol is what leaves this machine; for relay candidates it is
        // the protocol of the hop to the relay.
        protocol: protocolOf(local) ?? protocolOf(remote),
        rttMs: rtt !== null ? rtt * 1000 : null,
    };
}

function audio(record: StatsRecord): boolean {
    return (record.kind ?? record.mediaType) === 'audio';
}

/**
 * Reduces one `RTCStatsReport` (as an array of its entries) of one peer connection to the numbers
 * the panel needs. The publisher connection is the send path, the subscriber the receive path.
 * With several remote speakers the counters are summed over their inbound streams.
 */
export function sampleReport(
    records: StatsRecord[],
    direction: PathDirection,
): RawSample {
    const byId = new Map(records.map((record) => [record.id, record]));
    const sum = (list: StatsRecord[], key: string): number | null => {
        const values = list
            .map((record) => num(record[key]))
            .filter((value): value is number => value !== null);

        return values.length > 0
            ? values.reduce((total, value) => total + value, 0)
            : null;
    };

    const inbound = records.filter(
        (record) => record.type === 'inbound-rtp' && audio(record),
    );
    const outbound = records.filter(
        (record) => record.type === 'outbound-rtp' && audio(record),
    );
    const playout = records.filter(
        (record) =>
            record.type === 'media-playout' &&
            (record.kind === undefined || record.kind === 'audio'),
    );
    const first = (direction === 'send' ? outbound : inbound)[0];
    const codecRecord = first ? byId.get(str(first.codecId) ?? '') : undefined;
    const at =
        num(first?.timestamp) ??
        num(selectedCandidatePair(records)?.timestamp) ??
        0;

    return {
        direction,
        at,
        path: parsePath(records, direction),
        bytesSent: sum(outbound, 'bytesSent'),
        packetsSent: sum(outbound, 'packetsSent'),
        bytesReceived: sum(inbound, 'bytesReceived'),
        packetsReceived: sum(inbound, 'packetsReceived'),
        packetsLost: sum(inbound, 'packetsLost'),
        // Jitter is an instantaneous estimate (RFC 3550), not a counter: use the worst stream.
        jitterSeconds: inbound.reduce<number | null>((worst, record) => {
            const value = num(record.jitter);

            return value !== null && (worst === null || value > worst)
                ? value
                : worst;
        }, null),
        jitterBufferDelay: sum(inbound, 'jitterBufferDelay'),
        jitterBufferEmittedCount: sum(inbound, 'jitterBufferEmittedCount'),
        concealedSamples: sum(inbound, 'concealedSamples'),
        totalSamplesReceived: sum(inbound, 'totalSamplesReceived'),
        totalPlayoutDelay: sum(playout, 'totalPlayoutDelay'),
        totalPlayoutSamples: sum(playout, 'totalSamplesCount'),
        codec: codecRecord ? str(codecRecord.mimeType) : null,
        fmtp: codecRecord ? str(codecRecord.sdpFmtpLine) : null,
    };
}

function delta(current: number | null, previous: number | null): number | null {
    if (current === null || previous === null) {
        return null;
    }

    const difference = current - previous;

    // A counter that went down means the stream was replaced (re-join, track restart).
    return difference >= 0 ? difference : null;
}

/** `ptime=<ms>` of an fmtp line, e.g. `minptime=10;ptime=20;useinbandfec=1`. */
export function packetTimeOf(fmtp: string | null): number | null {
    const match = fmtp ? /(?:^|[;\s])ptime=(\d+(?:\.\d+)?)/.exec(fmtp) : null;

    return match ? Number(match[1]) : null;
}

/**
 * Turns two samples of the same connection into per-interval metrics. Without a previous sample
 * (or after a counter reset) the interval based values are `null`; the instantaneous ones (path,
 * RTT, jitter, codec) are always filled when the browser reports them.
 */
export function computeMetrics(
    current: RawSample,
    previous: RawSample | null,
): ConnectionMetrics {
    const usable =
        previous !== null && current.at > previous.at ? previous : null;
    const seconds = usable ? (current.at - usable.at) / 1000 : null;

    const bytes =
        current.direction === 'send'
            ? delta(current.bytesSent, usable?.bytesSent ?? null)
            : delta(current.bytesReceived, usable?.bytesReceived ?? null);

    const received = delta(
        current.packetsReceived,
        usable?.packetsReceived ?? null,
    );
    const lost = delta(current.packetsLost, usable?.packetsLost ?? null);
    const emitted = delta(
        current.jitterBufferEmittedCount,
        usable?.jitterBufferEmittedCount ?? null,
    );
    const buffered = delta(
        current.jitterBufferDelay,
        usable?.jitterBufferDelay ?? null,
    );
    const concealed = delta(
        current.concealedSamples,
        usable?.concealedSamples ?? null,
    );
    const samples = delta(
        current.totalSamplesReceived,
        usable?.totalSamplesReceived ?? null,
    );
    const playoutDelay = delta(
        current.totalPlayoutDelay,
        usable?.totalPlayoutDelay ?? null,
    );
    const playoutSamples = delta(
        current.totalPlayoutSamples,
        usable?.totalPlayoutSamples ?? null,
    );

    return {
        direction: current.direction,
        path: current.path,
        kbps:
            bytes !== null && seconds !== null && seconds > 0
                ? (bytes * 8) / seconds / 1000
                : null,
        jitterMs:
            current.jitterSeconds !== null
                ? current.jitterSeconds * 1000
                : null,
        // Seconds a sample waited, summed, divided by the samples emitted: mean delay in seconds.
        jitterBufferMs:
            buffered !== null && emitted !== null && emitted > 0
                ? (buffered / emitted) * 1000
                : null,
        lossPercent:
            lost !== null && received !== null && received + lost > 0
                ? (lost / (received + lost)) * 100
                : null,
        concealedPercent:
            concealed !== null && samples !== null && samples > 0
                ? (concealed / samples) * 100
                : null,
        playoutMs:
            playoutDelay !== null &&
            playoutSamples !== null &&
            playoutSamples > 0
                ? (playoutDelay / playoutSamples) * 1000
                : null,
        codec: current.codec,
        packetTimeMs: packetTimeOf(current.fmtp),
    };
}

/** Keys of the German hints in `lang/de/web_voice.php` (`diagnostics.hint_*`). */
export type PathVerdict =
    'ok' | 'tcp' | 'relay' | 'reflexive' | 'slow' | 'unknown';

/** RTT above this on a venue network points at Wi-Fi, a relay, or a far-away server. */
export const SLOW_RTT_MS = 30;

/**
 * Classifies a path. Order matters: relay and TCP are the classic causes of bad latency and win
 * over a slow RTT, which in turn wins over "looks fine". A server-reflexive path is not wrong,
 * but on a LAN it means the browser did not use the local address (split DNS or a wrong
 * `node_ip`), so it is flagged too.
 */
export function judgePath(path: PathInfo): PathVerdict {
    if (path.localType === null && path.remoteType === null) {
        return 'unknown';
    }

    if (path.localType === 'relay' || path.remoteType === 'relay') {
        return 'relay';
    }

    if (path.protocol === 'tcp') {
        return 'tcp';
    }

    if (path.localType === 'srflx' || path.remoteType === 'srflx') {
        return 'reflexive';
    }

    if (path.rttMs !== null && path.rttMs > SLOW_RTT_MS) {
        return 'slow';
    }

    return 'ok';
}

/** Entries of an `RTCStatsReport` as plain objects (a report is a read-only Map). */
export function reportToRecords(report: {
    forEach: (callback: (value: unknown) => void) => void;
}): StatsRecord[] {
    const records: StatsRecord[] = [];

    report.forEach((value) => {
        if (
            typeof value === 'object' &&
            value !== null &&
            typeof (value as StatsRecord).id === 'string' &&
            typeof (value as StatsRecord).type === 'string'
        ) {
            records.push(value as StatsRecord);
        }
    });

    return records;
}
