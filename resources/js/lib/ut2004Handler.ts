/**
 * Pure helpers that build the files needed to register `ut2004://` as a URL
 * handler on a player's machine (Windows `.reg`, Linux `.desktop`). Everything
 * runs in the browser; nothing is uploaded. Paths are validated strictly
 * (absolute, no control or quote characters) so the generated files cannot
 * carry injected registry keys or desktop-entry fields.
 *
 * Untested against a real UT2004 installation.
 */

export type HandlerOs = 'windows' | 'linux';

// Windows: drive letter, then backslash-separated safe segments, ending in .exe.
const WINDOWS_PATH =
    /^[A-Za-z]:\\(?:[A-Za-z0-9 _.()+@~-]+\\)*[A-Za-z0-9 _.()+@~-]+\.exe$/i;
// Linux: absolute, safe segments only (spaces allowed, quoted in Exec).
const LINUX_PATH = /^\/(?:[A-Za-z0-9 _.+@~-]+\/)*[A-Za-z0-9 _.+@~-]+$/;

export function isValidHandlerPath(os: HandlerOs, path: string): boolean {
    const trimmed = path.trim();

    if (trimmed.length === 0 || trimmed.length > 260) {
        return false;
    }

    if (trimmed.split(/[\\/]/).includes('..')) {
        return false;
    }

    return (os === 'windows' ? WINDOWS_PATH : LINUX_PATH).test(trimmed);
}

/** Escapes a value for use inside a double-quoted `.reg` string. */
function regEscape(value: string): string {
    return value.replace(/\\/g, '\\\\').replace(/"/g, '\\"');
}

/**
 * The `.reg` file text (CRLF line endings, as regedit expects) for a given
 * UT2004.exe path, or null when the path is invalid. Writes under HKCU only.
 */
export function buildRegFile(exePath: string): string | null {
    const path = exePath.trim();

    if (!isValidHandlerPath('windows', path)) {
        return null;
    }

    const command = `"${path}" "%1"`;

    return [
        'Windows Registry Editor Version 5.00',
        '',
        '[HKEY_CURRENT_USER\\Software\\Classes\\ut2004]',
        '@="URL:UT2004 Protocol"',
        '"URL Protocol"=""',
        '',
        '[HKEY_CURRENT_USER\\Software\\Classes\\ut2004\\shell\\open\\command]',
        `@="${regEscape(command)}"`,
        '',
    ].join('\r\n');
}

function dirname(path: string): string {
    return path.slice(0, path.lastIndexOf('/')) || '/';
}

/** The `ut2004-handler.desktop` text for a System/UT2004 path, or null when invalid. */
export function buildDesktopFile(binaryPath: string): string | null {
    const path = binaryPath.trim();

    if (!isValidHandlerPath('linux', path)) {
        return null;
    }

    return [
        '[Desktop Entry]',
        'Type=Application',
        'Name=UT2004 (ut2004:// handler)',
        `Exec="${path}" %u`,
        `Path=${dirname(path)}`,
        'Terminal=false',
        'NoDisplay=true',
        'MimeType=x-scheme-handler/ut2004;',
        '',
    ].join('\n');
}

/** The two shell commands that register the desktop file as the scheme handler. */
export const LINUX_REGISTER_COMMANDS: readonly string[] = [
    'xdg-mime default ut2004-handler.desktop x-scheme-handler/ut2004',
    'update-desktop-database ~/.local/share/applications',
];
