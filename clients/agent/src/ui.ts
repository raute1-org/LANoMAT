import { createInterface } from 'node:readline/promises';
import { stdin, stderr, stdout } from 'node:process';
import { redactSecret } from './http.js';
import { formatBytes, formatSpeed } from './util.js';

export interface LoggerOptions {
    stdout?: NodeJS.WriteStream;
    stderr?: NodeJS.WriteStream;
    color?: boolean;
    secret?: string;
}

const ANSI = {
    reset: '\u001b[0m',
    bold: '\u001b[1m',
    dim: '\u001b[2m',
    red: '\u001b[31m',
    green: '\u001b[32m',
    yellow: '\u001b[33m',
    blue: '\u001b[34m',
    magenta: '\u001b[35m',
    cyan: '\u001b[36m',
};

export class Logger {
    readonly out: NodeJS.WriteStream;
    readonly err: NodeJS.WriteStream;
    readonly color: boolean;
    private secret?: string;

    constructor(options: LoggerOptions = {}) {
        this.out = options.stdout ?? stdout;
        this.err = options.stderr ?? stderr;
        this.color =
            options.color ??
            Boolean(this.out.isTTY && !process.env.NO_COLOR && process.env.TERM !== 'dumb');
        this.secret = options.secret;
    }

    setSecret(secret: string | undefined): void {
        this.secret = secret;
    }

    private paint(code: string, text: string): string {
        return this.color ? `${code}${text}${ANSI.reset}` : text;
    }

    private clean(text: string): string {
        return redactSecret(text, this.secret);
    }

    line(text = ''): void {
        this.out.write(`${this.clean(text)}\n`);
    }

    info(text: string): void {
        this.line(text);
    }

    dim(text: string): void {
        this.line(this.paint(ANSI.dim, text));
    }

    success(text: string): void {
        this.line(`${this.paint(ANSI.green, '✔')} ${text}`);
    }

    warn(text: string): void {
        this.err.write(`${this.paint(ANSI.yellow, '!')} ${this.clean(text)}\n`);
    }

    error(text: string): void {
        this.err.write(`${this.paint(ANSI.red, '✖')} ${this.clean(text)}\n`);
    }

    heading(text: string): void {
        this.line(this.paint(ANSI.bold, text));
    }

    key(text: string): string {
        return this.paint(ANSI.cyan, text);
    }

    labelLan(): string {
        return this.paint(ANSI.green, 'LAN');
    }

    labelExternal(): string {
        return this.paint(ANSI.yellow, 'EXTERNAL');
    }

    labelMissing(): string {
        return this.paint(ANSI.red, 'MISSING');
    }
}

export async function promptYesNo(question: string, defaultYes = true): Promise<boolean> {
    const rl = createInterface({ input: stdin, output: stdout });

    try {
        const suffix = defaultYes ? '[Y/n]' : '[y/N]';
        const answer = (await rl.question(`${question} ${suffix} `)).trim().toLowerCase();

        if (answer === '') {
            return defaultYes;
        }

        return answer === 'y' || answer === 'yes' || answer === 'j' || answer === 'ja';
    } finally {
        rl.close();
    }
}

/** Hidden input for API keys. Falls back to a plain prompt when stdin is not a TTY. */
export async function promptSecret(question: string): Promise<string> {
    if (!stdin.isTTY) {
        const rl = createInterface({ input: stdin, output: stdout });

        try {
            return (await rl.question(question)).trim();
        } finally {
            rl.close();
        }
    }

    stdout.write(question);

    return new Promise<string>((resolve, reject) => {
        let value = '';
        const onData = (chunk: Buffer): void => {
            const text = chunk.toString('utf8');

            for (const char of text) {
                if (char === '\r' || char === '\n') {
                    stdin.setRawMode(false);
                    stdin.off('data', onData);
                    stdout.write('\n');
                    resolve(value.trim());

                    return;
                }

                if (char === '\u0003') {
                    stdin.setRawMode(false);
                    stdin.off('data', onData);
                    stdout.write('\n');
                    reject(new Error('Cancelled'));

                    return;
                }

                if (char === '\u007f' || char === '\b') {
                    value = value.slice(0, -1);
                    continue;
                }

                value += char;
            }
        };

        stdin.setRawMode(true);
        stdin.resume();
        stdin.on('data', onData);
    });
}

export interface ProgressRenderer {
    update(input: {
        filename: string;
        received: number;
        total?: number;
        bytesPerSecond?: number;
        state: 'downloading' | 'done' | 'skipped' | 'failed' | 'queued';
    }): void;
    finish(): void;
}

/** Compact per-file progress: bytes, percentage and speed. Non-TTY prints one line per state change. */
export function createProgressRenderer(logger: Logger): ProgressRenderer {
    const states = new Map<string, string>();
    let lastRender = 0;

    return {
        update(input): void {
            const percent =
                input.total && input.total > 0
                    ? `${Math.min(100, Math.floor((input.received / input.total) * 100))}%`
                    : '—';
            const line = `${input.filename} ${formatBytes(input.received)}${
                input.total ? `/${formatBytes(input.total)}` : ''
            } ${percent} ${formatSpeed(input.bytesPerSecond)} ${input.state}`;

            if (!logger.out.isTTY) {
                if (states.get(input.filename) !== input.state) {
                    states.set(input.filename, input.state);
                    logger.line(line);
                }

                return;
            }

            const now = Date.now();

            if (input.state === 'downloading' && now - lastRender < 250) {
                return;
            }

            lastRender = now;
            states.set(input.filename, input.state);
            logger.out.write(`\r${line.padEnd(78).slice(0, 78)}`);
        },
        finish(): void {
            if (logger.out.isTTY && states.size > 0) {
                logger.out.write('\n');
            }
        },
    };
}

export function table(rows: string[][], gap = 2): string {
    if (rows.length === 0) {
        return '';
    }

    const widths: number[] = [];

    for (const row of rows) {
        row.forEach((cell, index) => {
            widths[index] = Math.max(widths[index] ?? 0, visibleLength(cell));
        });
    }

    return rows
        .map((row) =>
            row
                .map((cell, index) => {
                    const padding = (widths[index] ?? 0) - visibleLength(cell);

                    return index === row.length - 1 ? cell : `${cell}${' '.repeat(padding + gap)}`;
                })
                .join(''),
        )
        .join('\n');
}

export function visibleLength(value: string): number {
    // eslint-disable-next-line no-control-regex
    return value.replace(/\u001b\[[0-9;]*m/g, '').length;
}

export function formatState(state: 'downloading' | 'done' | 'skipped' | 'failed' | 'queued'): string {
    switch (state) {
        case 'downloading':
            return 'downloading';
        case 'done':
            return 'done';
        case 'skipped':
            return 'verified, skipped';
        case 'failed':
            return 'failed';
        case 'queued':
            return 'queued';
    }
}
