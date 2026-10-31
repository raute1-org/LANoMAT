const UNITS = ['B', 'KB', 'MB', 'GB', 'TB'] as const;

/**
 * German-formatted byte size (1024 base, one decimal from KB up): "512 B",
 * "1,5 MB", "14,0 GB". Machine data, so callers render it in `font-mono`.
 */
export function formatBytes(bytes: number): string {
    let value = Math.max(0, bytes);
    let unit = 0;

    while (value >= 1024 && unit < UNITS.length - 1) {
        value /= 1024;
        unit++;
    }

    if (unit === 0) {
        return `${Math.round(value)} B`;
    }

    return `${value.toLocaleString('de-DE', {
        minimumFractionDigits: 1,
        maximumFractionDigits: 1,
    })} ${UNITS[unit]}`;
}

/** "12,3 MB/s" */
export function formatSpeed(bytesPerSecond: number): string {
    return `${formatBytes(bytesPerSecond)}/s`;
}

/** Remaining time for a transfer: "unter 1 Min.", "12 Min.", "2 Std. 5 Min.". */
export function formatDuration(seconds: number): string {
    if (!Number.isFinite(seconds) || seconds < 0) {
        return '';
    }

    const minutes = Math.ceil(seconds / 60);

    if (minutes < 1 || seconds < 60) {
        return 'unter 1 Min.';
    }

    if (minutes < 60) {
        return `${minutes} Min.`;
    }

    const hours = Math.floor(minutes / 60);
    const rest = minutes % 60;

    return rest === 0 ? `${hours} Std.` : `${hours} Std. ${rest} Min.`;
}
