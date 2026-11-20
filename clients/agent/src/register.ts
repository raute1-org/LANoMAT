import { accessSync, constants, existsSync } from 'node:fs';
import { readFile } from 'node:fs/promises';
import { homedir } from 'node:os';
import { delimiter, join, resolve } from 'node:path';
import { ensureDir, writeFileAtomic } from './util.js';
import { defaultExecutor, type CommandExecutor } from './runner.js';

export interface RegisterOptions {
    platform?: NodeJS.Platform;
    env?: NodeJS.ProcessEnv;
    home?: string;
    nodePath?: string;
    cliPath?: string;
    applicationsDir?: string;
    yes?: boolean;
    executor?: CommandExecutor;
    updateDesktopDatabase?: (directory: string) => Promise<boolean>;
    log?: (line: string) => void;
    warn?: (line: string) => void;
}

export interface RegisterResult {
    platform: NodeJS.Platform;
    path?: string;
    desktopFile?: string;
    command?: string;
    written: boolean;
    databaseUpdated: boolean;
    key: string;
}

export const SCHEME_KEY = 'HKCU\\Software\\Classes\\lanomat\\shell\\open\\command';

export function applicationsDir(options: RegisterOptions = {}): string {
    const env = options.env ?? process.env;

    if (env.LANOMAT_APPLICATIONS_DIR) {
        return resolve(env.LANOMAT_APPLICATIONS_DIR);
    }

    const dataHome = env.XDG_DATA_HOME || join(options.home ?? homedir(), '.local', 'share');

    return join(dataHome, 'applications');
}

function execQuote(value: string): string {
    return /\s/.test(value) ? `"${value.replace(/"/g, '\\"')}"` : value;
}

export function desktopEntry(nodePath: string, cliPath: string): string {
    return [
        '[Desktop Entry]',
        'Type=Application',
        'Name=LANoMAT Agent',
        'Comment=Install and launch LANoMAT games',
        `Exec=${execQuote(nodePath)} ${execQuote(cliPath)} open %u`,
        'Terminal=false',
        'NoDisplay=true',
        'MimeType=x-scheme-handler/lanomat;',
        'Categories=Utility;',
        'StartupNotify=false',
        'X-LANoMAT-Agent=true',
        '',
    ].join('\n');
}

export function windowsRegisterCommand(nodePath: string, cliPath: string): string {
    const value = `\\"${nodePath}\\" \\"${cliPath}\\" open \\"%1\\"`;

    return `reg add ${SCHEME_KEY} /ve /d "${value}" /f`;
}

function findOnPath(name: string, env: NodeJS.ProcessEnv): string | null {
    for (const dir of (env.PATH ?? '').split(delimiter).filter(Boolean)) {
        const candidate = join(dir, name);

        if (!existsSync(candidate)) {
            continue;
        }

        try {
            accessSync(candidate, constants.X_OK);

            return candidate;
        } catch {
            // not executable
        }
    }

    return null;
}

/**
 * Register the `lanomat://` scheme for the current user. Linux writes only
 * `lanomat-agent.desktop`; an unrelated desktop file is never touched.
 */
export async function registerAgentScheme(options: RegisterOptions = {}): Promise<RegisterResult> {
    const platform = options.platform ?? process.platform;
    const env = options.env ?? process.env;
    const log = options.log ?? (() => undefined);
    const warn = options.warn ?? (() => undefined);
    const executor = options.executor ?? defaultExecutor;
    const nodePath = options.nodePath ?? process.execPath;
    const cliPath = options.cliPath ?? process.argv[1] ?? '';

    if (platform === 'win32') {
        const command = windowsRegisterCommand(nodePath, cliPath);

        log(command);

        if (!options.yes) {
            log('');
            log('The registry was NOT changed. Re-run with --yes to execute the command.');

            return { platform, command, written: false, databaseUpdated: false, key: SCHEME_KEY };
        }

        const result = await executor(
            'reg',
            ['add', SCHEME_KEY, '/ve', '/d', `"${nodePath}" "${cliPath}" open "%1"`, '/f'],
            { cwd: process.cwd() },
        );

        if (result.code !== 0) {
            throw new Error(`reg add failed with exit code ${result.code}`);
        }

        return { platform, command, written: true, databaseUpdated: false, key: SCHEME_KEY };
    }

    if (platform !== 'linux') {
        warn(`Automatic scheme registration is implemented for Linux and Windows, not ${platform}.`);
        warn(`Register lanomat:// by hand with: ${windowsRegisterCommand(nodePath, cliPath)}`);

        return { platform, written: false, databaseUpdated: false, key: SCHEME_KEY };
    }

    const dir = options.applicationsDir ?? applicationsDir(options);
    const path = join(dir, 'lanomat-agent.desktop');
    const desktopFile = desktopEntry(nodePath, cliPath);
    let existing: string | null = null;

    try {
        existing = await readFile(path, 'utf8');
    } catch {
        // first registration
    }

    const written = existing !== desktopFile;

    if (written) {
        await ensureDir(dir, 0o755);
        await writeFileAtomic(path, desktopFile, 0o644);
    }

    log(written ? `Wrote ${path}` : `Already registered: ${path}`);
    log('MimeType=x-scheme-handler/lanomat;');

    let databaseUpdated = false;

    if (options.updateDesktopDatabase) {
        databaseUpdated = await options.updateDesktopDatabase(dir);
    } else {
        const updater = findOnPath('update-desktop-database', env);

        if (updater) {
            const result = await executor(updater, [dir], { cwd: process.cwd() });
            databaseUpdated = result.code === 0;

            if (!databaseUpdated) {
                warn('update-desktop-database failed; the scheme may still work after the next login.');
            }
        } else {
            warn('update-desktop-database not found; install desktop-file-utils if the buttons do not open.');
        }
    }

    return {
        platform,
        path,
        desktopFile,
        written,
        databaseUpdated,
        key: SCHEME_KEY,
    };
}
