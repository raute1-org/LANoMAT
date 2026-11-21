// LANoMAT Discord agent bridge.
//
// Two layers, in this order:
//
//   1. `pi --print` with the LANoMAT MCP server configured in
//      `~/.pi/agent/mcp.json` (same shape as clients/agent/src/mcp.ts). This is
//      the "real" agent: it can call any MCP tool, not just the four the
//      deterministic fallback knows.
//   2. A deterministic JSON-RPC client for the four read-only LANoMAT tools
//      (`recipes_list`, `recipe_get`, `recipe_install_plan`, `servers_list`).
//      It runs when pi is missing, times out, fails, or no model is configured.
//      It never invents ids, prices, dates or joins: every line comes straight
//      from tool output.
//
// The module has no discord.js dependency so the bot and the tests can use it
// without a live Gateway connection.
import { spawn as nodeSpawn } from 'node:child_process';
import { mkdir, readFile, writeFile } from 'node:fs/promises';
import { homedir } from 'node:os';
import { dirname, join } from 'node:path';

export const DEFAULT_LANOMAT_URL = 'https://lan.raute1.org';
export const DEFAULT_MAX_CHARS = 2000;
export const DEFAULT_AGENT_TIMEOUT_MS = 90_000;
export const DEFAULT_MCP_TIMEOUT_MS = 15_000;
export const DEFAULT_RATE_LIMIT = 5;
export const DEFAULT_RATE_WINDOW_MS = 10 * 60_000;

/** Placeholder pi expands from the process environment (the token never hits disk). */
export const LANOMAT_TOKEN_PLACEHOLDER = '${LANOMAT_TOKEN}';

export const AGENT_SYSTEM_PROMPT = `Du bist der LANoMAT-Discord-Assistent für eine LAN-Party.

Deine Aufgabe: kurze, hilfreiche Antworten auf Deutsch zu Spielen, Installationen,
Servern, Events, Turnieren und dem Launcher geben.

Regeln:
- Nutze für jede Tatsache die LANoMAT-MCP-Tools. Erfinde niemals Slugs, IDs,
  Preise, Termine, Dateinamen, Serveradressen oder Download-Links.
- Wenn ein Tool nichts findet oder einen Fehler meldet, sage das offen. Rate nicht.
- Für "installiere <Spiel>": recipes_list -> recipe_get -> recipe_install_plan.
  Nenne zuerst den Plan für das passende Betriebssystem (Windows, Linux, macOS;
  im Zweifel Windows) und danach die LAN-Downloads. Externe Downloads nur nennen,
  wenn das Tool sie als "external" markiert.
- Verlinke eine Installation immer mit beiden Wegen:
  lanomat://install/<slug> und https://lan.raute1.org/games/<slug>
  (ersetze <slug> durch den Slug aus recipes_list/recipe_get).
- Für Serverfragen: servers_list. Gib Titel, Spiel, Status und join_info aus.
- Antworte knapp (unter 1800 Zeichen), Discord-tauglich, ohne Markdown-Tabellen.
- Führe keine Shell-Befehle aus und schreibe keine Dateien.`;

export function isEnabled(value) {
    return ['1', 'true', 'yes', 'on'].includes(String(value ?? '').toLowerCase());
}

/** `~/.pi/agent` (pi's own override wins), matching clients/agent's config.ts. */
export function piAgentDir(env = process.env) {
    if (env.PI_CODING_AGENT_DIR) {
        return env.PI_CODING_AGENT_DIR;
    }

    if (env.LANOMAT_PI_DIR) {
        return env.LANOMAT_PI_DIR;
    }

    return join(env.HOME || homedir(), '.pi', 'agent');
}

export function mcpConfigPath(env = process.env) {
    return join(piAgentDir(env), 'mcp.json');
}

/** Same entry clients/agent writes: URL + bearer placeholder + deferred tools. */
export function buildMcpConfig(baseUrl, token = LANOMAT_TOKEN_PLACEHOLDER) {
    const url = `${String(baseUrl || DEFAULT_LANOMAT_URL).replace(/\/+$/, '')}/mcp`;

    return {
        mcpServers: {
            lanomat: {
                url,
                headers: {
                    Authorization: `Bearer ${token}`,
                },
                description: 'LANoMAT: events, teams, tournaments, files, game recipes and LAN install plans.',
                exposure: 'deferred',
            },
        },
    };
}

/**
 * Merge the LANoMAT server into an existing mcp.json without dropping other
 * servers. Returns the path that was written.
 */
export async function writeMcpConfig({
    env = process.env,
    baseUrl = DEFAULT_LANOMAT_URL,
    token = LANOMAT_TOKEN_PLACEHOLDER,
} = {}) {
    const path = mcpConfigPath(env);
    let existing = null;

    try {
        const parsed = JSON.parse(await readFile(path, 'utf8'));

        if (parsed && typeof parsed === 'object' && !Array.isArray(parsed)) {
            existing = parsed;
        }
    } catch (error) {
        if (error?.code !== 'ENOENT') {
            throw error;
        }
    }

    const merged = { ...(existing ?? {}), mcpServers: { ...(existing?.mcpServers ?? {}) } };
    merged.mcpServers.lanomat = buildMcpConfig(baseUrl, token).mcpServers.lanomat;

    await mkdir(dirname(path), { recursive: true, mode: 0o700 });
    await writeFile(path, `${JSON.stringify(merged, null, 2)}\n`, { mode: 0o600 });

    return path;
}

/** The argv `pi` gets in print mode. Built-ins are off so Discord cannot touch the host. */
export function buildPiArgs({
    prompt,
    systemPrompt = AGENT_SYSTEM_PROMPT,
    model,
    provider,
    skillsDir,
    noBuiltinTools = true,
} = {}) {
    const args = ['--print', '--no-session', '--no-context-files'];

    if (noBuiltinTools) {
        args.push('--no-builtin-tools');
    }

    if (provider) {
        args.push('--provider', provider);
    }

    if (model) {
        args.push('--model', model);
    }

    if (skillsDir) {
        args.push('--skill', skillsDir);
    }

    args.push('--system-prompt', systemPrompt, prompt);

    return args;
}

/** The prompt handed to pi: who asked, whether it was a DM, and the question. */
export function buildUserPrompt({ text, author, isDM = false, channelName }) {
    const where = isDM ? 'per DM' : `im Kanal ${channelName || '#discord'}`;
    const who = author ? ` von ${author}` : '';

    return `Frage${who} ${where}:\n${String(text ?? '').trim()}\n\nAntworte auf Deutsch, kurz und konkret für Discord.`;
}

/** Strip ANSI colours and pi's incidental blank lines from print output. */
export function cleanPiOutput(stdout) {
    return String(stdout ?? '')
        .replace(/\u001b\[[0-9;]*m/g, '')
        .replace(/\r\n/g, '\n')
        .trim();
}

/**
 * Run `pi --print` with a hard timeout. Resolves (never rejects) with
 * `{ok, text, code, error, timedOut}` so the caller can fall back.
 */
export function runPi({
    argv,
    binary = 'pi',
    timeoutMs = DEFAULT_AGENT_TIMEOUT_MS,
    cwd,
    env = process.env,
    spawnImpl = nodeSpawn,
} = {}) {
    return new Promise((resolve) => {
        let child;
        let stdout = '';
        let stderr = '';
        let settled = false;
        let timedOut = false;

        const finish = (result) => {
            if (settled) {
                return;
            }

            settled = true;
            clearTimeout(timer);
            resolve(result);
        };

        try {
            child = spawnImpl(binary, argv, {
                cwd,
                env,
                stdio: ['ignore', 'pipe', 'pipe'],
            });
        } catch (error) {
            resolve({ ok: false, text: '', code: null, error: error?.message ?? String(error), timedOut: false });

            return;
        }

        const timer = setTimeout(() => {
            timedOut = true;
            child.kill('SIGKILL');
        }, timeoutMs);

        child.stdout?.on('data', (chunk) => {
            stdout += chunk.toString();
        });
        child.stderr?.on('data', (chunk) => {
            stderr += chunk.toString();
        });
        child.on('error', (error) => {
            finish({ ok: false, text: '', code: null, error: error?.message ?? String(error), timedOut });
        });
        child.on('close', (code) => {
            const text = cleanPiOutput(stdout);

            finish({
                ok: !timedOut && code === 0 && text !== '',
                text,
                code,
                error: timedOut ? `pi timed out after ${timeoutMs} ms` : (text === '' ? cleanPiOutput(stderr).slice(-500) : null),
                timedOut,
            });
        });
    });
}

/**
 * Split text into Discord-sized chunks (max 2000 UTF-16 code units). Prefers
 * line and word boundaries and never cuts a surrogate pair in half.
 */
export function splitMessage(text, limit = DEFAULT_MAX_CHARS) {
    const normalized = String(text ?? '').replace(/\r\n/g, '\n').trim();

    if (normalized === '') {
        return [];
    }

    if (normalized.length <= limit) {
        return [normalized];
    }

    const chunks = [];
    let current = '';

    const flush = () => {
        if (current.trim() !== '') {
            chunks.push(current.trim());
        }

        current = '';
    };

    const hardSplit = (line) => {
        let remaining = line;

        while (remaining.length > limit) {
            let cut = limit;
            const code = remaining.charCodeAt(cut - 1);

            if (code >= 0xd800 && code <= 0xdbff) {
                cut -= 1;
            }

            const space = remaining.lastIndexOf(' ', cut);

            if (space > Math.floor(limit / 2)) {
                cut = space;
            }

            const head = remaining.slice(0, cut).trim();

            if (head !== '') {
                chunks.push(head);
            }

            remaining = remaining.slice(cut).trimStart();
        }

        return remaining;
    };

    for (const line of normalized.split('\n')) {
        // A single over-long line is split first; flushing keeps the output order
        // (previous lines must not end up after this line's chunks).
        let rest = line;

        if (line.length > limit) {
            flush();
            rest = hardSplit(line);
        }

        if (current === '') {
            current = rest;
        } else if ((current + '\n' + rest).length <= limit) {
            current += '\n' + rest;
        } else {
            flush();
            current = rest;
        }
    }

    flush();

    return chunks.length > 0 ? chunks : [normalized.slice(0, limit)];
}

/** Minimal JSON-RPC MCP client for the streamable-HTTP endpoint. No SDK dependency. */
export class McpJsonRpcClient {
    constructor({
        baseUrl = DEFAULT_LANOMAT_URL,
        token,
        fetchImpl = globalThis.fetch,
        timeoutMs = DEFAULT_MCP_TIMEOUT_MS,
    } = {}) {
        this.url = `${String(baseUrl || DEFAULT_LANOMAT_URL).replace(/\/+$/, '')}/mcp`;
        this.token = token ?? '';
        this.fetchImpl = fetchImpl;
        this.timeoutMs = timeoutMs;
        this.nextId = 1;
        this.sessionId = null;
        this.initialized = false;
    }

    headers() {
        const headers = {
            'Content-Type': 'application/json',
            Accept: 'application/json, text/event-stream',
            Authorization: `Bearer ${this.token}`,
        };

        if (this.sessionId) {
            headers['Mcp-Session-Id'] = this.sessionId;
        }

        return headers;
    }

    async post(body) {
        const response = await this.fetchImpl(this.url, {
            method: 'POST',
            headers: this.headers(),
            body: JSON.stringify(body),
            signal: AbortSignal.timeout(this.timeoutMs),
        });

        const sessionId = response.headers?.get?.('mcp-session-id');

        if (sessionId) {
            this.sessionId = sessionId;
        }

        const text = await response.text();

        if (!response.ok) {
            throw new Error(`LANoMAT MCP HTTP ${response.status}: ${text.slice(0, 200)}`);
        }

        return parseJsonRpcPayload(text);
    }

    async rpc(method, params = {}) {
        const id = this.nextId++;
        const payload = await this.post({ jsonrpc: '2.0', id, method, params });
        const message = payload.find((entry) => entry.id === id) ?? payload.at(-1);

        if (!message) {
            throw new Error(`LANoMAT MCP returned no response for ${method}`);
        }

        if (message.error) {
            throw new Error(`LANoMAT MCP ${method}: ${message.error.message ?? 'unknown error'}`);
        }

        return message.result ?? {};
    }

    async initialize() {
        if (this.initialized) {
            return;
        }

        await this.rpc('initialize', {
            protocolVersion: '2025-06-18',
            capabilities: {},
            clientInfo: { name: 'lanomat-discord-agent', version: '1.0.0' },
        });

        // Notifications have no id and normally answer 202; a body is harmless.
        await this.post({ jsonrpc: '2.0', method: 'notifications/initialized', params: {} }).catch(() => {});
        this.initialized = true;
    }

    async listTools({ perPage = 100, cursor } = {}) {
        await this.initialize();
        const params = { per_page: perPage };

        if (cursor) {
            params.cursor = cursor;
        }

        return this.rpc('tools/list', params);
    }

    async callTool(name, args = {}) {
        await this.initialize();

        const result = await this.rpc('tools/call', { name, arguments: args });

        return normalizeToolResult(result);
    }
}

/** Accept both plain JSON and SSE (`data: {...}`) responses. */
export function parseJsonRpcPayload(text) {
    const body = String(text ?? '').trim();

    if (body === '') {
        return [];
    }

    if (body.startsWith('{') || body.startsWith('[')) {
        const parsed = JSON.parse(body);

        return Array.isArray(parsed) ? parsed : [parsed];
    }

    const messages = [];

    for (const line of body.split('\n')) {
        const trimmed = line.trim();

        if (!trimmed.startsWith('data:')) {
            continue;
        }

        const data = trimmed.slice(5).trim();

        if (data === '' || data === '[DONE]') {
            continue;
        }

        try {
            messages.push(JSON.parse(data));
        } catch {
            // A partial/non-JSON SSE frame is not a response.
        }
    }

    return messages;
}

/** Normalize a tools/call result: structuredContent wins, then a JSON text block. */
export function normalizeToolResult(result) {
    const structured = result?.structuredContent;
    const blocks = Array.isArray(result?.content) ? result.content : [];
    const text = blocks
        .filter((block) => block?.type === 'text' && typeof block.text === 'string')
        .map((block) => block.text)
        .join('\n')
        .trim();

    let parsed = null;

    if (text !== '') {
        try {
            parsed = JSON.parse(text);
        } catch {
            parsed = null;
        }
    }

    return {
        isError: Boolean(result?.isError),
        structured: structured && typeof structured === 'object' ? structured : parsed,
        text,
    };
}

/** German OS words -> the MCP tool's enum. Default: windows (matches the tool). */
export function detectOs(text) {
    const value = String(text ?? '').toLowerCase();

    if (/\b(macos|mac os|macbook|apple|osx|os x)\b/.test(value) || /\bmac\b/.test(value)) {
        return 'macos';
    }

    if (/\b(linux|ubuntu|steam ?deck|arch|fedora|debian)\b/.test(value)) {
        return 'linux';
    }

    return 'windows';
}

const QUERY_FILLER = new Set([
    'aber', 'alle', 'auch', 'auf', 'aus', 'bei', 'bitte', 'das', 'dein', 'dem', 'den', 'der',
    'die', 'dies', 'doch', 'du', 'ein', 'eine', 'einen', 'einem', 'einer', 'er', 'es', 'für',
    'fuer', 'game', 'games', 'gibt', 'habe', 'haben', 'hat', 'hilfe', 'ich', 'im', 'in', 'ins',
    'install', 'installation', 'installiere', 'installieren', 'installier', 'ist', 'kann',
    'kannst', 'können', 'koennen', 'lanomat', 'mal', 'man', 'mich', 'mir', 'mit', 'muss',
    'nach', 'nicht', 'noch', 'oder', 'auf', 'unter', 'ueber', 'über', 'am', 'beim', 'vom', 'ohne',
    'gegen', 'spiel', 'spiele', 'spielen', 'und', 'uns', 'von',
    'was', 'welche', 'welcher', 'welches', 'wie', 'wir', 'wo', 'zu', 'zum', 'zur', 'ask',
    'frage', 'help', 'the', 'what', 'which', 'how', 'can', 'you', 'me', 'my', 'is', 'are',
    'please', 'for', 'with', 'and', 'or', 'a', 'an', 'to', 'of', 'do', 'i',
    // OS words select the plan, not the recipe.
    'linux', 'windows', 'win', 'win10', 'win11', 'win32', 'win64', 'mac', 'macos', 'osx',
    'ubuntu', 'arch', 'fedora', 'debian', 'steamdeck',
].map((word) => word.normalize('NFKD').replace(/[\u0300-\u036f]/g, '')));

/**
 * Turn a Discord question into the likely catalogue fragment:
 * "Wie installiere ich Unreal Tournament 2004?" -> "unreal tournament 2004".
 */
export function extractQuery(text) {
    return String(text ?? '')
        .replace(/<@!?\d+>/g, ' ')
        .replace(/^[!/?](ask|game)\b/i, ' ')
        .toLowerCase()
        .normalize('NFKD')
        .replace(/[\u0300-\u036f]/g, '')
        .replace(/[^\p{L}\p{N}+#._ -]+/gu, ' ')
        .split(/\s+/)
        .map((word) => word.replace(/^[-_.]+|[-_.]+$/g, ''))
        .filter((word) => word !== '' && !QUERY_FILLER.has(word))
        .join(' ')
        .trim();
}

/**
 * A message counts as an agent question when it is a DM, mentions the bot, or
 * starts with `!ask`/`!game`/`?ask`. Discord.js objects are read structurally,
 * so this is testable without a Gateway connection.
 */
export function classifyMessage(message, { botId, guildId = '', channelId = '' } = {}) {
    if (!message || message.author?.bot || message.system || message.webhookId) {
        return { match: false };
    }

    const channelType = message.channel?.type;
    const isDM = channelType === 1 // ChannelType.DM
        || message.channel?.isDMBased?.() === true;
    const content = String(message.content ?? '');

    if (isDM) {
        return { match: content.trim() !== '', question: content.trim(), isDM: true };
    }

    if (guildId && message.guildId && message.guildId !== guildId) {
        return { match: false };
    }

    if (channelId && message.channelId !== channelId) {
        return { match: false };
    }

    if (botId && new RegExp(`<@!?${botId}>`).test(content)) {
        return {
            match: true,
            question: content.replace(new RegExp(`<@!?${botId}>`, 'g'), '').trim(),
            isDM: false,
        };
    }

    const prefixed = content.match(/^\s*[!?](?:ask|game)\b[:\s]*(.*)$/is);

    if (prefixed) {
        return { match: true, question: prefixed[1].trim(), isDM: false };
    }

    return { match: false };
}

export function detectIntent(text) {
    const value = String(text ?? '').toLowerCase();

    if (/\b(server|serverliste|join|beitreten|mitspielen|connect|adresse|ip)\b/.test(value)) {
        return 'servers';
    }

    if (/\b(welche spiele|spieleliste|spielkatalog|katalog|liste|list|games|spiele)\b/.test(value)
        && !/\b(install|installier)\b/.test(value)) {
        return 'list';
    }

    return 'install';
}

function webBase(baseUrl) {
    return String(baseUrl || DEFAULT_LANOMAT_URL).replace(/\/+$/, '');
}

function gameLinks(slug, baseUrl) {
    return `lanomat://install/${slug} · ${webBase(baseUrl)}/games/${slug}`;
}

function listRecipes(recipes, baseUrl) {
    const lines = recipes.slice(0, 12).map((recipe) => {
        const platforms = Array.isArray(recipe.platforms) && recipe.platforms.length > 0
            ? ` [${recipe.platforms.join(', ')}]`
            : '';

        return `- ${recipe.title} (\`${recipe.slug}\`)${platforms} — ${gameLinks(recipe.slug, baseUrl)}`;
    });

    const more = recipes.length > 12 ? `\n… und ${recipes.length - 12} weitere.` : '';

    return `Diese Spiele kann ich installieren:\n${lines.join('\n')}${more}`;
}

function formatInstallAnswer({ recipe, plan }, os, baseUrl) {
    const slug = recipe.slug;
    const files = Array.isArray(plan.files) ? plan.files : [];
    const downloads = files.filter((file) => ['matched', 'external'].includes(file.status));
    const missing = Array.isArray(plan.missing) ? plan.missing.filter(Boolean) : [];
    const steps = Array.isArray(plan.steps) ? plan.steps : [];

    const fileLines = downloads.slice(0, 6).map((file) => {
        const source = file.status === 'matched' ? 'LAN' : 'extern';
        const url = file.download_url || file.fallback_url || file.url || '';

        return `- ${file.label || file.requirement} (${source}): ${url || 'keine URL im Plan'}`;
    });

    if (downloads.length > 6) {
        fileLines.push(`- … und ${downloads.length - 6} weitere Dateien.`);
    }

    const stepLines = steps.slice(0, 4).map((step, index) => {
        const optional = step.optional ? ' (optional)' : '';

        return `${index + 1}. ${step.title}${optional}`;
    });

    const launch = recipe.launch_command || plan.recipe?.launch_command || plan.recipe?.play_url || recipe.launch_target;
    const lines = [
        `Installiere ${recipe.title} (\`${slug}\`):`,
        `Plan für ${os}: ${plan.target_dir || 'siehe Rezept'}`,
        `Schritte: ${steps.length} · Downloads: ${downloads.length} · Fehlt: ${missing.length > 0 ? missing.join(', ') : 'nichts'}`,
    ];

    if (fileLines.length > 0) {
        lines.push('Dateien:', ...fileLines);
    }

    if (stepLines.length > 0) {
        lines.push('Ablauf:', ...stepLines);
    }

    if (launch) {
        lines.push(`Start: ${launch}`);
    }

    lines.push(`Mehr: ${gameLinks(slug, baseUrl)}`);

    return lines.join('\n');
}

/** `join_info` is an object in the tool payload ({address, port, connect_string, password}). */
function formatJoinInfo(value) {
    if (value === null || value === undefined || value === '') {
        return '';
    }

    if (typeof value === 'string') {
        return value;
    }

    if (typeof value !== 'object') {
        return String(value);
    }

    const address = value.address
        ? `${value.address}${value.port ? `:${value.port}` : ''}`
        : null;
    const parts = [
        value.connect_string,
        address,
        value.password ? `Passwort: ${value.password}` : null,
    ].filter(Boolean);

    return [...new Set(parts)].join(' · ');
}

function formatServers(servers, baseUrl) {
    if (!Array.isArray(servers) || servers.length === 0) {
        return `Es sind gerade keine Server eingetragen. Serverbrowser: ${webBase(baseUrl)}/servers`;
    }

    const lines = servers.slice(0, 10).map((server) => {
        const title = server.title || 'Unbenannter Server';
        const game = server.game ? ` (${server.game})` : '';
        const status = server.status ? ` · Status: ${server.status}` : '';
        const joinInfo = formatJoinInfo(server.join_info);
        const join = joinInfo ? ` · Beitreten: ${joinInfo}` : '';

        return `- ${title}${game}${status}${join}`;
    });

    const more = servers.length > 10 ? `\n… und ${servers.length - 10} weitere.` : '';

    return `Server:\n${lines.join('\n')}${more}\nServerbrowser: ${webBase(baseUrl)}/servers`;
}

/**
 * Deterministic answer built only from MCP tool output. Throws only when the
 * server itself cannot be reached, so the caller can show a friendly error.
 */
export async function fallbackAnswer(question, { mcp, baseUrl = DEFAULT_LANOMAT_URL } = {}) {
    if (!mcp) {
        throw new Error('no MCP client');
    }

    const intent = detectIntent(question);
    const os = detectOs(question);

    if (intent === 'servers') {
        const result = await mcp.callTool('servers_list', {});

        if (result.isError) {
            return `Serverabfrage fehlgeschlagen: ${result.text || 'unbekannter Fehler'}`;
        }

        return formatServers(result.structured?.servers, baseUrl);
    }

    const query = extractQuery(question);
    let recipes = [];

    if (query !== '') {
        const result = await mcp.callTool('recipes_list', { query });

        recipes = result.structured?.recipes ?? [];

        // "unreal tournament 2004" can miss when the recipe says "unreal-tournament-2004";
        // retry the individual meaningful words before giving up.
        if (recipes.length === 0 && query.includes(' ')) {
            for (const word of query.split(' ').filter((part) => part.length >= 3)) {
                const retry = await mcp.callTool('recipes_list', { query: word });
                const found = retry.structured?.recipes ?? [];

                if (found.length > 0) {
                    recipes = found;

                    break;
                }
            }
        }
    }

    if (recipes.length === 0) {
        const all = await mcp.callTool('recipes_list', {});

        recipes = all.structured?.recipes ?? [];
    }

    if (recipes.length === 0) {
        return 'Ich finde gerade kein passendes Spiel im LANoMAT-Katalog. '
            + `Schau auf ${webBase(baseUrl)}/games oder frage nach einem Server.`;
    }

    if (recipes.length > 1) {
        const exact = recipes.filter((recipe) => recipe.slug === query.replace(/\s+/g, '-'));

        if (exact.length === 1) {
            recipes = exact;
        } else {
            return listRecipes(recipes, baseUrl);
        }
    }

    const recipe = recipes[0];
    const detail = await mcp.callTool('recipe_get', { slug: recipe.slug });

    if (detail.isError) {
        return `Das Rezept \`${recipe.slug}\` konnte ich nicht laden: ${detail.text || 'unbekannter Fehler'}`;
    }

    const plan = await mcp.callTool('recipe_install_plan', { slug: recipe.slug, os, dry_run: true });

    if (plan.isError) {
        return `Der Installationsplan für \`${recipe.slug}\` (${os}) ist nicht verfügbar: ${plan.text || 'unbekannter Fehler'}`;
    }

    return formatInstallAnswer(
        {
            recipe: detail.structured?.recipe ?? recipe,
            plan: plan.structured ?? {},
        },
        os,
        baseUrl,
    );
}

/** Per-user sliding-window limiter shared by mentions and `/ask`. */
export class RateLimiter {
    constructor({ limit = DEFAULT_RATE_LIMIT, windowMs = DEFAULT_RATE_WINDOW_MS, clock = Date.now } = {}) {
        this.limit = Math.max(1, Number(limit) || DEFAULT_RATE_LIMIT);
        this.windowMs = Math.max(1000, Number(windowMs) || DEFAULT_RATE_WINDOW_MS);
        this.clock = clock;
        this.hits = new Map();
    }

    take(key) {
        const now = this.clock();
        const recent = (this.hits.get(key) ?? []).filter((at) => now - at < this.windowMs);

        if (recent.length >= this.limit) {
            const retryAfterMs = Math.max(0, this.windowMs - (now - recent[0]));
            this.hits.set(key, recent);

            return { allowed: false, retryAfterMs };
        }

        recent.push(now);
        this.hits.set(key, recent);

        // Keep the map bounded on a long-running bot.
        if (this.hits.size > 1000) {
            for (const [candidate, times] of this.hits) {
                if (times.every((at) => now - at >= this.windowMs)) {
                    this.hits.delete(candidate);
                }
            }
        }

        return { allowed: true, retryAfterMs: 0 };
    }
}

function agentConfig(env = process.env) {
    return {
        baseUrl: env.LANOMAT_URL || DEFAULT_LANOMAT_URL,
        token: env.LANOMAT_TOKEN || '',
        piBinary: env.PI_BIN || 'pi',
        model: env.PI_MODEL || '',
        provider: env.PI_PROVIDER || '',
        skillsDir: env.PI_SKILLS_DIR || '',
        timeoutMs: Number(env.DISCORD_AGENT_TIMEOUT_MS || DEFAULT_AGENT_TIMEOUT_MS),
        mcpTimeoutMs: Number(env.DISCORD_AGENT_MCP_TIMEOUT_MS || DEFAULT_MCP_TIMEOUT_MS),
    };
}

/**
 * The bot-facing agent. `answer()` tries pi, then the deterministic MCP
 * fallback, and returns `{text, source}` where source is `pi`, `fallback`,
 * `empty` or `error`.
 */
export function createAgent(options = {}) {
    const env = options.env ?? process.env;
    const config = { ...agentConfig(env), ...options };
    let mcp = null;
    let configReady = null;

    const client = () => {
        mcp ??= new McpJsonRpcClient({
            baseUrl: config.baseUrl,
            token: config.token,
            fetchImpl: options.fetchImpl,
            timeoutMs: config.mcpTimeoutMs,
        });

        return mcp;
    };

    const ensureConfig = async () => {
        configReady ??= writeMcpConfig({ env, baseUrl: config.baseUrl }).catch((error) => {
            configReady = null;
            throw error;
        });

        return configReady;
    };

    return {
        enabled: Boolean(config.token),
        baseUrl: config.baseUrl,

        async answer({ text, author, isDM = false, channelName } = {}) {
            const question = String(text ?? '').trim();

            if (question === '') {
                return { text: 'Schreib mir eine Frage, z. B. „Installiere UT2004“ oder „Welche Server gibt es?“.', source: 'empty' };
            }

            let piFailure = null;

            if (config.preferPi !== false && config.token !== '') {
                try {
                    await ensureConfig();
                    const result = await runPi({
                        argv: buildPiArgs({
                            prompt: buildUserPrompt({ text: question, author, isDM, channelName }),
                            model: config.model || undefined,
                            provider: config.provider || undefined,
                            skillsDir: config.skillsDir || undefined,
                        }),
                        binary: config.piBinary,
                        timeoutMs: config.timeoutMs,
                        env,
                        spawnImpl: options.spawnImpl,
                    });

                    if (result.ok) {
                        return { text: result.text, source: 'pi' };
                    }

                    piFailure = result.error || 'pi lieferte keine Antwort';
                } catch (error) {
                    piFailure = error?.message ?? String(error);
                }
            }

            if (config.token === '') {
                return {
                    text: 'Der LANoMAT-Assistent ist noch nicht konfiguriert (LANOMAT_TOKEN fehlt). '
                        + `Spiele und Server findest du unter ${webBase(config.baseUrl)}/games.`,
                    source: 'error',
                };
            }

            try {
                const answer = await fallbackAnswer(question, { mcp: client(), baseUrl: config.baseUrl });

                return { text: answer, source: 'fallback', piFailure };
            } catch (error) {
                const reason = String(error?.message ?? error).slice(0, 200);

                return {
                    text: `Ich konnte LANoMAT gerade nicht erreichen. Bitte später erneut versuchen oder ${webBase(config.baseUrl)} öffnen.`,
                    source: 'error',
                    error: reason,
                    piFailure,
                };
            }
        },
    };
}
