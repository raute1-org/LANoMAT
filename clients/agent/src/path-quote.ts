import type { Os, PlanStep } from './types.js';

/**
 * The shells a recipe can name in `commands[].shell` and in a launch target.
 * `src/runner.ts` is the only place that turns these names into processes.
 */
export type RecipeShell = 'powershell' | 'cmd' | 'bash';

/**
 * Variables that hold a resolved filesystem path and are pasted into the recipe's commands as
 * text (`{{target}}` etc.). `install.ts` builds them; `runner.ts` and `launch.ts` substitute them.
 */
export const PATH_VARIABLES = ['target', 'target_dir', 'recipe_dir', 'downloads', 'avatars'] as const;

export type PathVariable = (typeof PATH_VARIABLES)[number];

/** The shells a plan for this OS can actually run. Without an OS, all of them. */
export function shellsForOs(os: Os | undefined): RecipeShell[] {
    if (os === 'windows') {
        return ['powershell', 'cmd'];
    }

    if (os === undefined) {
        return ['powershell', 'cmd', 'bash'];
    }

    return ['bash'];
}

export type UnsafePathKind =
    | 'single-quote'
    | 'double-quote'
    | 'smart-quote'
    | 'backtick'
    | 'dollar'
    | 'percent'
    | 'exclamation'
    | 'caret'
    | 'shell-operator'
    | 'control'
    | 'trailing-backslash'
    | 'double-backslash';

export interface UnsafePathProblem {
    kind: UnsafePathKind;
    /** The offending character, with a readable name, e.g. `' (single quote)`. */
    character: string;
    /** 0-based index of the character in the path (the path length for a trailing backslash). */
    index: number;
}

const SHELL_OPERATORS: Record<string, string> = {
    ';': 'a command separator',
    '&': 'a command separator',
    '|': 'a pipe',
    '<': 'input redirection',
    '>': 'output redirection',
};

/**
 * PowerShell treats typographic quotation marks as quotation marks
 * (about_Quoting_Rules: "PowerShell treats smart quotation marks … as normal quotation marks for
 * strings"). A folder named with the apostrophe from a word processor would end the string.
 */
const SMART_QUOTES: Record<string, string> = {
    '\u2018': '‘ (U+2018, curly single quote)',
    '\u2019': '’ (U+2019, curly apostrophe)',
    '\u201c': '“ (U+201C, curly double quote)',
    '\u201d': '” (U+201D, curly double quote)',
};

function controlName(code: number): string {
    const names: Record<number, string> = {
        0x00: 'null',
        0x09: 'tab',
        0x0a: 'line feed',
        0x0d: 'carriage return',
    };
    const hex = code.toString(16).toUpperCase().padStart(4, '0');

    return `U+${hex} (${names[code] ?? 'control character'})`;
}

/**
 * Decide whether a resolved, absolute path may be pasted into the recipe's commands.
 *
 * The placeholder is substituted *as text* into a command that the recipe author has already
 * quoted (`'{{target}}'`, `"{{target}}"`) or, rarely, left unquoted. The agent cannot re-quote
 * afterwards without rewriting the recipe, so a path that can end, extend or reinterpret that
 * quoting is refused before anything is downloaded, created or run. The rule is the union of the
 * dangerous characters of every quoting style the given shells allow:
 *
 * - `'` (single quote) ends a single-quoted literal in bash and PowerShell. PowerShell escapes it
 *   by doubling, bash has no escape inside single quotes — a value pasted in cannot be fixed.
 *   (about_Quoting_Rules, "Including quote characters in a string".) cmd treats `'` literally.
 * - `"` (double quote) ends a double-quoted argument in bash, PowerShell and cmd.
 * - The typographic quotation marks `‘ ’ “ ”` are quotation marks to PowerShell as well
 *   (about_Quoting_Rules: "PowerShell treats smart quotation marks … as normal quotation marks
 *   for strings"). A folder named `Anna’s Games` by a word processor would end the string; refused
 *   on Windows only, bash treats them literally.
 * - `` ` `` (backtick) is PowerShell's escape character (the only one) and bash's command
 *   substitution inside double quotes. (about_Quoting_Rules, "Double-quoted strings".)
 * - `$` starts a variable reference or `$(…)` subexpression in double-quoted bash and PowerShell.
 *   Both substitution styles are in use in the recipe catalogue, so it is refused for both.
 * - `%` is expanded as `%NAME%` by cmd *even inside a quoted argument* (SS64, "Escape Percents";
 *   `cmd /?`). Only cmd has this, so it is refused on Windows only.
 * - `!` is expanded as `!NAME!` by cmd when a recipe turns on delayed expansion
 *   (`setlocal enabledelayedexpansion`); bash `-c` is non-interactive, so history expansion is
 *   off, and PowerShell treats it literally. Refused on Windows only.
 * - `^` is cmd's escape character (`^&`, `^^`, line continuation). Refused on Windows only.
 * - `;`, `&`, `|`, `<`, `>` are operators in every shell outside quotes (separator, pipe,
 *   redirection). Inside quotes they are literal, but nothing forbids a recipe to use a
 *   placeholder unquoted, and then `--target '/tmp/x;some command'` would run that command
 *   (shown with a real bash in the tests). Refused everywhere: they are rare in folder names
 *   and the price of being wrong is a second command.
 * - Control characters (U+0000–U+001F, U+007F): a line feed or carriage return ends the command or
 *   the quoted literal; a tab splits an unquoted argument. Refused everywhere.
 * - A trailing `\` escapes the closing quote of a double-quoted bash argument (`"C:\Games\"` is
 *   an unterminated string) and, on Windows, the child's command-line parser reads `\"` as a
 *   literal quote — that is why `shellQuote('cmd', …)` doubles a trailing backslash.
 * - Two backslashes in a row: bash reduces `\\` to `\` inside double quotes (`"a\\b"` reaches the
 *   command as `a\b`), so the command would silently use a different folder. Refused for bash;
 *   PowerShell and cmd treat `\` literally, which keeps UNC targets such as `\\nas\share` usable
 *   on Windows.
 *
 * Deliberately accepted, because the value is inside the recipe's quotes and no shell re-reads
 * those characters there: spaces, dots, hyphens, underscores, `,`, `=`, `(`, `)`, `{`, `}`,
 * `#`, `~`, `*`, `?`, `[`, `]`, a leading `-` (an absolute path cannot start with `-` anyway) and
 * non-ASCII characters — umlauts and other letters in a user name are legitimate, and no concrete
 * break could be shown for them (the smart quotation marks above are the exception).
 * `C:\Program Files (x86)\Game` works because cmd, PowerShell and bash treat parentheses literally
 * inside quotes; it is the unquoted use of the placeholder (not the path) that would break there.
 *
 * Unquoted placeholders are the recipe's choice: a space pasted unquoted already splits the
 * argument before any of the characters above can. The rule therefore covers the quoted forms and
 * takes the union of those, not the (much larger) set of unquoted shell metacharacters.
 */
export function findUnsafePath(value: string, shells: RecipeShell[]): UnsafePathProblem | null {
    const bash = shells.includes('bash');
    const powershell = shells.includes('powershell');
    const cmd = shells.includes('cmd');

    for (let index = 0; index < value.length; index += 1) {
        const char = value[index] as string;
        const code = value.codePointAt(index) as number;

        if (code < 0x20 || code === 0x7f) {
            return { kind: 'control', character: controlName(code), index };
        }

        if (char === "'" && (bash || powershell)) {
            return { kind: 'single-quote', character: "' (single quote)", index };
        }

        if (powershell && Object.hasOwn(SMART_QUOTES, char)) {
            return { kind: 'smart-quote', character: SMART_QUOTES[char] as string, index };
        }

        if (char === '"') {
            return { kind: 'double-quote', character: '" (double quote)', index };
        }

        if (char === '`' && (bash || powershell)) {
            return { kind: 'backtick', character: '` (backtick)', index };
        }

        if (char === '$' && (bash || powershell)) {
            return { kind: 'dollar', character: '$ (dollar sign)', index };
        }

        if (char === '%' && cmd) {
            return { kind: 'percent', character: '% (percent sign)', index };
        }

        if (char === '!' && cmd) {
            return { kind: 'exclamation', character: '! (exclamation mark)', index };
        }

        if (char === '^' && cmd) {
            return { kind: 'caret', character: '^ (caret)', index };
        }

        if (char in SHELL_OPERATORS) {
            return { kind: 'shell-operator', character: `${char} (${SHELL_OPERATORS[char]})`, index };
        }

        if (char === '\\' && bash && value[index + 1] === '\\') {
            return { kind: 'double-backslash', character: '\\ (backslash, doubled)', index };
        }
    }

    if (value.endsWith('\\')) {
        return { kind: 'trailing-backslash', character: '\\ (backslash, at the end)', index: value.length - 1 };
    }

    return null;
}

function variableLabel(variable: PathVariable): string {
    switch (variable) {
        case 'downloads':
            return 'download';
        case 'avatars':
            return 'avatar';
        default:
            return 'install';
    }
}

function variableHint(variable: PathVariable, problem: UnsafePathProblem): string {
    if (variable === 'avatars') {
        return 'Point LANOMAT_AVATARS_DIR at another folder; launch also takes --avatars <dir>.';
    }

    const folder =
        problem.kind === 'trailing-backslash'
            ? 'Choose another folder with --target <folder>, without a trailing backslash'
            : 'Choose another folder with --target <folder>';

    const home =
        variable === 'downloads'
            ? ' (the download folder is the one the plan names)'
            : '; if your user name contains an apostrophe, use a folder outside your home directory such as C:\\Games\\<name> or /opt/games/<name>';

    return `${folder}${home}.`;
}

function problemReason(problem: UnsafePathProblem, placeholder: string): string {
    switch (problem.kind) {
        case 'single-quote':
            return `it contains ${problem.character}, which ends the single-quoted argument around ${placeholder} in the recipe's commands`;
        case 'double-quote':
            return `it contains ${problem.character}, which ends the double-quoted argument around ${placeholder} in the recipe's commands`;
        case 'smart-quote':
            return `it contains ${problem.character}, which PowerShell reads as a quotation mark inside the argument around ${placeholder}`;
        case 'backtick':
            return `it contains ${problem.character}, which bash reads as command substitution and PowerShell as an escape inside the quoted argument around ${placeholder}`;
        case 'dollar':
            return `it contains ${problem.character}, which bash and PowerShell expand as a variable or subexpression inside the double-quoted argument around ${placeholder}`;
        case 'percent':
            return `it contains ${problem.character}, which cmd expands as a variable even inside the quoted argument around ${placeholder}`;
        case 'exclamation':
            return `it contains ${problem.character}, which cmd expands when a recipe turns on delayed expansion inside the quoted argument around ${placeholder}`;
        case 'caret':
            return `it contains ${problem.character}, which cmd reads as an escape character in the argument around ${placeholder}`;
        case 'shell-operator':
            return `it contains ${problem.character}, which splits or redirects the command around ${placeholder} when a shell sees it outside quotes`;
        case 'control':
            return `it contains ${problem.character}, a control character that ends the argument or the line around ${placeholder}`;
        case 'trailing-backslash':
            return `it ends with ${problem.character}, which escapes the closing quote around ${placeholder} in a bash or cmd command`;
        case 'double-backslash':
            return `it contains ${problem.character}, which bash turns into one backslash inside the quoted argument around ${placeholder}, so the command would use a different folder`;
    }
}

export class UnsafeRecipePathError extends Error {
    readonly variable: string;
    readonly path: string;
    readonly problem: UnsafePathProblem;

    constructor(variable: PathVariable, path: string, problem: UnsafePathProblem, placeholder: string) {
        super(
            `Cannot use "${path}" as the ${variableLabel(variable)} folder: ${problemReason(problem, placeholder)}. ` +
                'This is about how the recipe\'s commands are quoted, not a mistake on your side. ' +
                variableHint(variable, problem),
        );
        this.name = 'UnsafeRecipePathError';
        this.variable = variable;
        this.path = path;
        this.problem = problem;
    }
}

function isPathVariable(name: string): name is PathVariable {
    return (PATH_VARIABLES as readonly string[]).includes(name);
}

/** Throw `UnsafeRecipePathError` when `value` cannot be pasted into a command for `shells`. */
export function assertRecipePath(
    variable: PathVariable,
    value: string,
    shells: RecipeShell[],
    placeholder = `{{${variable}}}`,
): void {
    const problem = findUnsafePath(value, shells);

    if (problem) {
        throw new UnsafeRecipePathError(variable, value, problem, placeholder);
    }
}

/** The path placeholders that occur in a template, in the order they are checked. */
export function pathVariablesIn(template: string): PathVariable[] {
    const found = new Set<PathVariable>();

    for (const match of template.matchAll(/\{\{([a-z0-9_]+)\}\}/gi)) {
        const name = (match[1] ?? '').toLowerCase();

        if (isPathVariable(name)) {
            found.add(name);
        }
    }

    return PATH_VARIABLES.filter((name) => found.has(name));
}

/**
 * Check every path variable that `template` references and that `variables` resolves. The target
 * is checked before the download folder, so the player is told about the folder they can change.
 */
export function assertSubstitutableVariables(
    template: string,
    variables: Record<string, string>,
    shells: RecipeShell[],
): void {
    for (const variable of pathVariablesIn(template)) {
        const value = variables[variable];

        if (value !== undefined) {
            assertRecipePath(variable, value, shells, `{{${variable}}}`);
        }
    }
}

/**
 * Check every string of a step that is later substituted: commands, verify checks and download
 * entries. Used by `install` before it downloads or creates a folder, and by `applyStepVariables`.
 */
export function assertStepPathsSafe(
    step: PlanStep,
    variables: Record<string, string>,
    shells: RecipeShell[],
): void {
    for (const command of step.commands ?? []) {
        assertSubstitutableVariables(command.run, variables, unionShells(shells, [command.shell]));
    }

    for (const check of step.verify ?? []) {
        if ('path' in check) {
            assertSubstitutableVariables(check.path, variables, shells);
            continue;
        }

        assertSubstitutableVariables(check.command, variables, unionShells(shells, [check.shell ?? 'bash']));

        for (const arg of check.args ?? []) {
            assertSubstitutableVariables(arg, variables, unionShells(shells, [check.shell ?? 'bash']));
        }
    }

    for (const download of step.downloads ?? []) {
        assertSubstitutableVariables(download.url, variables, shells);
        assertSubstitutableVariables(download.filename, variables, shells);
    }
}

function unionShells(shells: RecipeShell[], extra: Array<RecipeShell | undefined>): RecipeShell[] {
    const union = new Set<RecipeShell>(shells);

    for (const shell of extra) {
        if (shell) {
            union.add(shell);
        }
    }

    return [...union];
}
