import { spawn } from 'node:child_process';
import type { DeviceStart, DeviceToken } from './types.js';
import type { ApiClient } from './http.js';
import { ApiError } from './http.js';
import { sleep as realSleep } from './util.js';

export class DeviceFlowError extends Error {
    readonly code: string;

    constructor(code: string, message: string) {
        super(message);
        this.name = 'DeviceFlowError';
        this.code = code;
    }
}

export interface DeviceLoginOptions {
    api: ApiClient;
    deviceName: string;
    /** Called once the device code is known, before polling starts. */
    onDevice: (device: DeviceStart) => void | Promise<void>;
    onSlowDown?: (seconds: number) => void;
    onPending?: (attempt: number) => void;
    sleep?: (ms: number) => Promise<void>;
    clock?: () => Date;
    maxWaitMs?: number;
}

export interface DeviceLoginResult {
    token: DeviceToken;
    expiresAt: string;
}

/**
 * RFC 8628 style polling. `authorization_pending` keeps polling, `slow_down` adds 5 seconds to
 * the interval (RFC 8628 §3.5), `expired_token`/`access_denied`/`invalid_grant` abort.
 */
export async function runDeviceLogin(options: DeviceLoginOptions): Promise<DeviceLoginResult> {
    const sleep = options.sleep ?? realSleep;
    const clock = options.clock ?? (() => new Date());
    const startedAt = clock().getTime();

    const device = await options.api.startDevice(options.deviceName);
    await options.onDevice(device);

    const deadline = options.maxWaitMs
        ? startedAt + options.maxWaitMs
        : startedAt + device.expires_in * 1000;

    let intervalMs = Math.max(1, device.interval) * 1000;
    let attempt = 0;

    for (;;) {
        if (clock().getTime() >= deadline) {
            throw new DeviceFlowError('expired_token', 'The device code expired. Run "lanomat-agent login" again.');
        }

        await sleep(intervalMs);
        attempt += 1;
        options.onPending?.(attempt);

        let result;

        try {
            result = await options.api.pollDeviceToken(device.device_code);
        } catch (error) {
            if (error instanceof ApiError && error.status === 429) {
                intervalMs += 5000;
                options.onSlowDown?.(Math.round(intervalMs / 1000));
                continue;
            }

            throw error;
        }

        if (result.status === 'ok') {
            const expiresIn = result.token.expires_in;
            const expiresAt = new Date(clock().getTime() + expiresIn * 1000).toISOString();

            return { token: result.token, expiresAt };
        }

        switch (result.error) {
            case 'authorization_pending':
                continue;
            case 'slow_down':
                intervalMs += 5000;
                options.onSlowDown?.(Math.round(intervalMs / 1000));
                continue;
            case 'expired_token':
                throw new DeviceFlowError('expired_token', 'The device code expired. Run "lanomat-agent login" again.');
            case 'access_denied':
                throw new DeviceFlowError('access_denied', 'The login was denied on the LANoMAT server.');
            case 'invalid_grant':
                throw new DeviceFlowError('invalid_grant', 'The device code is invalid. Run "lanomat-agent login" again.');
            default:
                throw new DeviceFlowError(result.error, result.message);
        }
    }
}

export function deviceName(hostname: string, platform: NodeJS.Platform = process.platform): string {
    const label = platform === 'win32' ? 'PC' : platform === 'darwin' ? 'Mac' : 'PC';

    return `LANoMAT Agent on ${hostname || label}`;
}

/** Best-effort browser open. Never fails the login when xdg-open/start is unavailable. */
export function openBrowser(
    url: string,
    platform: NodeJS.Platform = process.platform,
    spawnImpl: typeof spawn = spawn,
): void {
    const command = platform === 'win32' ? 'cmd' : platform === 'darwin' ? 'open' : 'xdg-open';
    const args = platform === 'win32' ? ['/c', 'start', '', url] : [url];

    try {
        const child = spawnImpl(command, args, { detached: true, stdio: 'ignore' });

        child.on('error', () => undefined);
        child.unref();
    } catch {
        // Printing the URL is enough.
    }
}

export function loginInstructions(device: DeviceStart): string {
    return [
        `Open ${device.verification_uri} in your browser`,
        `and enter the code: ${device.user_code}`,
        '',
        'Waiting for approval…',
    ].join('\n');
}
