import { describe, expect, it } from 'vitest';
import {
    assertRecipePath,
    findUnsafePath,
    pathVariablesIn,
    shellsForOs,
    UnsafeRecipePathError,
} from '../src/path-quote.js';

const windows = shellsForOs('windows');
const linux = shellsForOs('linux');

describe('recipe path quoting', () => {
    it('picks the shells of the OS', () => {
        expect(windows).toEqual(['powershell', 'cmd']);
        expect(linux).toEqual(['bash']);
        expect(shellsForOs('macos')).toEqual(['bash']);
        expect(shellsForOs(undefined)).toEqual(['powershell', 'cmd', 'bash']);
    });

    it('accepts ordinary Windows paths', () => {
        for (const path of [
            'C:\\Users\\Anna Müller\\Games\\Tiny Racer',
            'C:\\Program Files (x86)\\Game',
            'C:\\Games\\UT2004',
            // UNC: cmd and PowerShell treat the backslash literally.
            '\\\\nas\\share\\Games\\UT2004',
            'C:\\Games\\a-b_c.d',
            'C:\\Games\\a=b,c',
            'C:\\Games\\#1',
            'C:\\Games\\~backup',
            'C:\\Games\\a(b){c}[d]',
            'C:\\Games\\Anna Müller',
        ]) {
            expect(findUnsafePath(path, windows), path).toBeNull();
        }
    });

    it('accepts ordinary Linux paths, including umlauts and characters bash leaves alone in quotes', () => {
        for (const path of [
            '/home/anna/Games/tiny-racer',
            '/opt/games/Anna Müller',
            '/opt/games/Program Files (x86)',
            '/opt/games/100%',
            '/opt/games/a!b^c',
            '/opt/games/a#c~d{e}',
            '/opt/games/end/',
            // bash treats smart quotes literally; only PowerShell reads them as quotation marks.
            '/opt/games/Anna’s Games',
        ]) {
            expect(findUnsafePath(path, linux), path).toBeNull();
        }
    });

    it('refuses what ends or reinterprets the quoting on Windows', () => {
        const refused: Array<[string, string]> = [
            ["C:\\Users\\O'Brien\\Games", 'single-quote'],
            ['C:\\Users\\Anna’s Games', 'smart-quote'],
            ['C:\\Games\\“UT2004”', 'smart-quote'],
            ['C:\\Games\\say "hi"', 'double-quote'],
            ['C:\\Games\\a`b', 'backtick'],
            ['C:\\Games\\$Recycle', 'dollar'],
            ['C:\\Games\\100%', 'percent'],
            ['C:\\Games\\Hi!', 'exclamation'],
            ['C:\\Games\\a^b', 'caret'],
            ['C:\\Games\\D&D', 'shell-operator'],
            ['C:\\Games\\a|b', 'shell-operator'],
            ['C:\\Games\\a<b', 'shell-operator'],
            ['C:\\Games\\a>b', 'shell-operator'],
            ['C:\\Games\\a\nb', 'control'],
            ['C:\\Games\\a\tb', 'control'],
            ['C:\\Games\\', 'trailing-backslash'],
        ];

        for (const [path, kind] of refused) {
            expect(findUnsafePath(path, windows)?.kind, path).toBe(kind);
        }
    });

    it('refuses what ends or reinterprets the quoting on Linux and macOS', () => {
        const refused: Array<[string, string]> = [
            ["/home/o'brien/Games", 'single-quote'],
            ['/opt/games/a"b', 'double-quote'],
            ['/opt/games/a`b', 'backtick'],
            ['/opt/games/$Recycle', 'dollar'],
            ['/opt/games/a\nb', 'control'],
            ['/opt/games/a\\b\\', 'trailing-backslash'],
            // bash turns \\ into one backslash inside double quotes, so this would be a different folder.
            ['/opt/games/a\\\\b', 'double-backslash'],
        ];

        for (const [path, kind] of refused) {
            expect(findUnsafePath(path, linux)?.kind, path).toBe(kind);
        }
    });

    it('refuses shell operators on every platform: a placeholder may be used unquoted', () => {
        for (const path of ['/opt/games/D&D', '/tmp/ok;touch /tmp/other', '/opt/a|b', '/opt/a<b', '/opt/a>b']) {
            expect(findUnsafePath(path, linux)?.kind, path).toBe('shell-operator');
        }
    });

    it('keeps the per-platform difference: cmd expansion characters and UNC are Windows-only', () => {
        expect(findUnsafePath('/opt/games/100%', linux)).toBeNull();
        expect(findUnsafePath('C:\\Games\\100%', windows)?.kind).toBe('percent');
        expect(findUnsafePath('C:\\Games\\D&D', windows)?.kind).toBe('shell-operator');
        expect(findUnsafePath('\\\\nas\\share\\games', windows)).toBeNull();
        expect(findUnsafePath('/opt/games/a\\\\b', linux)?.kind).toBe('double-backslash');
    });

    it('names the path, the character and the way out in the error', () => {
        const error = (() => {
            try {
                assertRecipePath('target', "C:\\Users\\O'Brien\\Games", windows);
            } catch (caught) {
                return caught;
            }

            return null;
        })();

        expect(error).toBeInstanceOf(UnsafeRecipePathError);
        const text = (error as Error).message;

        expect(text).toContain('"C:\\Users\\O\'Brien\\Games"');
        expect(text).toContain('install folder');
        expect(text).toContain('single quote');
        expect(text).toContain('not a mistake on your side');
        expect(text).toContain('--target <folder>');
        expect(text).toContain('C:\\Games\\<name>');
        expect(text).toContain('/opt/games/<name>');
    });

    it('suggests a folder without a trailing backslash for that case', () => {
        expect(() => assertRecipePath('target', 'C:\\Games\\', windows)).toThrow(
            /ends with .*backslash.*--target <folder>, without a trailing backslash/,
        );
    });

    it('gives the avatar folder its own way out', () => {
        expect(() => assertRecipePath('avatars', "/home/o'brien/.local/share/lanomat/avatars", linux)).toThrow(
            /LANOMAT_AVATARS_DIR/,
        );
    });

    it('finds the path placeholders in a command', () => {
        expect(pathVariablesIn('Expand-Archive "{{downloads}}/a.zip" "{{target}}"')).toEqual([
            'target',
            'downloads',
        ]);
        expect(pathVariablesIn('echo {{host}} {{slug}}')).toEqual([]);
    });
});
