import assert from 'node:assert/strict';
import { EventEmitter } from 'node:events';
import { mkdtemp, readFile, rm } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { after, describe, it } from 'node:test';
import {
    AGENT_SYSTEM_PROMPT,
    DEFAULT_MAX_CHARS,
    McpJsonRpcClient,
    RateLimiter,
    buildMcpConfig,
    buildPiArgs,
    buildUserPrompt,
    classifyMessage,
    cleanPiOutput,
    createAgent,
    detectIntent,
    detectOs,
    extractQuery,
    fallbackAnswer,
    normalizeToolResult,
    parseJsonRpcPayload,
    piAgentDir,
    splitMessage,
    writeMcpConfig,
} from './agent.mjs';

const tempDirs = [];

after(async () => {
    await Promise.all(tempDirs.map((dir) => rm(dir, { recursive: true, force: true })));
});

async function tempDir() {
    const dir = await mkdtemp(join(tmpdir(), 'lanomat-agent-test-'));
    tempDirs.push(dir);

    return dir;
}

const recipeSummary = {
    slug: 'unreal-tournament-2004',
    title: 'Unreal Tournament 2004',
    summary: 'Arena shooter from 2004',
    platforms: ['windows', 'linux'],
    aliases: ['ut2004', 'ut 2k4'],
};

const installPlan = {
    recipe: { slug: 'unreal-tournament-2004', title: 'Unreal Tournament 2004', launch_command: './ut2004' },
    os: 'linux',
    target_dir: '~/Games/unreal-tournament-2004',
    steps: [
        { title: 'Basis installieren', optional: false },
        { title: 'Patch einspielen', optional: true },
    ],
    files: [
        {
            label: 'UT2004 Linux Installer',
            status: 'matched',
            download_url: 'https://lan.example/api/v1/files/7/download',
        },
        {
            label: 'Community Patch',
            status: 'external',
            fallback_url: 'https://example.test/patch.zip',
        },
    ],
    missing: [],
};

/** A fake MCP client that records calls and serves fixed data. */
function fakeMcp(overrides = {}) {
    const calls = [];
    const answers = {
        recipes_list: (args) => {
            if (args.query && !String(args.query).includes('unreal') && args.query !== 'ut2004') {
                return { recipes: [] };
            }

            return { recipes: [recipeSummary] };
        },
        recipe_get: () => ({ recipe: recipeSummary }),
        recipe_install_plan: () => installPlan,
        servers_list: () => ({
            servers: [{
                id: 1,
                title: 'UT2004 Hauptserver',
                game: 'Unreal Tournament 2004',
                status: 'online',
                join_info: { address: '10.23.0.40', port: 7777, connect_string: 'ut2004://10.23.0.40:7777' },
            }],
        }),
        ...overrides,
    };

    return {
        calls,
        async callTool(name, args = {}) {
            calls.push([name, args]);
            const value = answers[name]?.(args);

            if (value === undefined) {
                throw new Error(`unexpected tool ${name}`);
            }

            return { isError: false, structured: value, text: '' };
        },
    };
}

/** Minimal MCP transport fake for createAgent's fallback path. */
function fakeFetch() {
    return async (_url, init) => {
        const body = JSON.parse(init.body);

        if (body.method === 'notifications/initialized') {
            return new Response('', { status: 202 });
        }

        const result = body.method === 'initialize'
            ? { protocolVersion: '2025-06-18', serverInfo: { name: 'fake' } }
            : body.method === 'tools/call'
                ? {
                    structuredContent: body.params.name === 'recipes_list'
                        ? { recipes: [recipeSummary] }
                        : body.params.name === 'recipe_get'
                            ? { recipe: recipeSummary }
                            : { ...installPlan, os: body.params.arguments.os },
                }
                : {};

        return new Response(JSON.stringify({ jsonrpc: '2.0', id: body.id, result }), {
            status: 200,
            headers: { 'Content-Type': 'application/json' },
        });
    };
}

describe('mcp.json', () => {
    it('writes the same LANoMAT entry as clients/agent with a token placeholder', () => {
        const config = buildMcpConfig('https://lan.example/');

        assert.equal(config.mcpServers.lanomat.url, 'https://lan.example/mcp');
        assert.equal(config.mcpServers.lanomat.headers.Authorization, 'Bearer ${LANOMAT_TOKEN}');
        assert.equal(config.mcpServers.lanomat.exposure, 'deferred');
        assert.equal(config.mcpServers.lanomat.headers.Authorization.includes('lmcp_'), false);
    });

    it('merges into an existing mcp.json and keeps other servers', async () => {
        const dir = await tempDir();
        const env = { PI_CODING_AGENT_DIR: dir };

        await writeMcpConfig({ env, baseUrl: 'https://one.example' });
        await writeMcpConfig({ env, baseUrl: 'https://two.example' });

        const path = join(dir, 'mcp.json');
        const written = JSON.parse(await readFile(path, 'utf8'));

        assert.equal(piAgentDir(env), dir);
        assert.equal(written.mcpServers.lanomat.url, 'https://two.example/mcp');

        await writeMcpConfig({ env, baseUrl: 'https://two.example' });

        const again = JSON.parse(await readFile(path, 'utf8'));

        assert.equal(Object.keys(again.mcpServers).length, 1);
    });
});

describe('pi invocation', () => {
    it('prints non-interactively with a German system prompt and no built-in tools', () => {
        const args = buildPiArgs({ prompt: 'Installiere UT2004', model: 'test/model', provider: 'test' });

        assert.deepEqual(args.slice(0, 4), ['--print', '--no-session', '--no-context-files', '--no-builtin-tools']);
        assert.ok(args.includes('--no-builtin-tools'));
        assert.ok(args.includes('--system-prompt'));
        assert.ok(args.includes(AGENT_SYSTEM_PROMPT));
        assert.ok(args.includes('test/model'));
        assert.equal(args.at(-1), 'Installiere UT2004');
    });

    it('tells the model who asked and whether it was a DM', () => {
        const prompt = buildUserPrompt({ text: 'Welche Server?', author: 'Rene', isDM: true });

        assert.match(prompt, /Frage von Rene per DM/);
        assert.match(prompt, /Welche Server\?/);
        assert.match(prompt, /Deutsch/);
    });

    it('cleans ANSI output', () => {
        assert.equal(cleanPiOutput('\u001b[32mHallo\u001b[0m\n\n'), 'Hallo');
    });
});

describe('Discord message classification', () => {
    const botId = '1234';

    it('ignores bots, webhooks and system messages', () => {
        assert.equal(classifyMessage({ author: { bot: true }, content: '<@1234> hi' }, { botId }).match, false);
        assert.equal(classifyMessage({ author: {}, webhookId: 'w', content: '<@1234> hi' }, { botId }).match, false);
        assert.equal(classifyMessage({ author: {}, system: true }, { botId }).match, false);
    });

    it('accepts DMs, mentions and !ask/!/game prefixes', () => {
        assert.deepEqual(
            classifyMessage({ author: {}, channel: { type: 1 }, content: 'Server?' }, { botId }),
            { match: true, question: 'Server?', isDM: true },
        );
        assert.equal(classifyMessage({ author: {}, guildId: 'g', channelId: 'c', content: '<@1234> Installiere UT2004' }, { botId, guildId: 'g' }).question, 'Installiere UT2004');
        assert.equal(classifyMessage({ author: {}, guildId: 'g', channelId: 'c', content: '!ask welche Spiele?' }, { botId }).question, 'welche Spiele?');
        assert.equal(classifyMessage({ author: {}, guildId: 'g', channelId: 'c', content: '!game ut2004' }, { botId }).question, 'ut2004');
    });

    it('stays quiet outside the configured guild or channel and for plain chatter', () => {
        const message = { author: {}, guildId: 'other', channelId: 'c', content: '<@1234> hi' };

        assert.equal(classifyMessage(message, { botId, guildId: 'g' }).match, false);
        assert.equal(classifyMessage({ author: {}, guildId: 'g', channelId: 'other', content: '<@1234> hi' }, { botId, guildId: 'g', channelId: 'c' }).match, false);
        assert.equal(classifyMessage({ author: {}, guildId: 'g', channelId: 'c', content: 'nur so' }, { botId }).match, false);
    });
});

describe('chunking', () => {
    it('keeps every chunk under the Discord limit and preserves order', () => {
        const text = Array.from({ length: 300 }, (_, index) => `${index}: ${'x'.repeat(40)}`).join('\n');
        const chunks = splitMessage(text, 200);

        assert.ok(chunks.length > 1);
        assert.ok(chunks.every((chunk) => chunk.length <= 200));
        assert.deepEqual(chunks.join('\n').split('\n'), text.split('\n'));
    });

    it('hard-splits a single long word without cutting a surrogate pair', () => {
        const chunks = splitMessage(`😀${'a'.repeat(50)}`, 10);

        assert.ok(chunks.every((chunk) => chunk.length <= 10));
        assert.equal(chunks.join(''), `😀${'a'.repeat(50)}`);
    });

    it('defaults to 2000 characters', () => {
        assert.equal(DEFAULT_MAX_CHARS, 2000);
        assert.equal(splitMessage('x'.repeat(4500)).length, 3);
    });
});

describe('deterministic fallback', () => {
    it('extracts a sensible catalogue query and OS', () => {
        assert.equal(extractQuery('Wie installiere ich Unreal Tournament 2004?'), 'unreal tournament 2004');
        assert.equal(extractQuery('<@123> Installiere UT2004 bitte'), 'ut2004');
        assert.equal(extractQuery('welche Spiele gibt es?'), '');
        assert.equal(detectOs('Bitte für Linux installieren'), 'linux');
        assert.equal(detectOs('auf dem Mac'), 'macos');
        assert.equal(detectOs('einfach installieren'), 'windows');
        assert.equal(detectIntent('Welche Server gibt es?'), 'servers');
        assert.equal(detectIntent('Welche Spiele gibt es?'), 'list');
        assert.equal(detectIntent('Installiere UT2004'), 'install');
    });

    it('answers an install question from recipes_list/recipe_get/recipe_install_plan only', async () => {
        const mcp = fakeMcp();
        const answer = await fallbackAnswer('Installiere UT2004 unter Linux', { mcp, baseUrl: 'https://lan.example' });

        assert.deepEqual(mcp.calls.map(([name]) => name), ['recipes_list', 'recipe_get', 'recipe_install_plan']);
        assert.deepEqual(mcp.calls[2][1], { slug: 'unreal-tournament-2004', os: 'linux', dry_run: true });
        assert.match(answer, /Installiere Unreal Tournament 2004/);
        assert.match(answer, /lanomat:\/\/install\/unreal-tournament-2004/);
        assert.match(answer, /https:\/\/lan\.example\/games\/unreal-tournament-2004/);
        assert.match(answer, /UT2004 Linux Installer \(LAN\)/);
        assert.match(answer, /Community Patch \(extern\)/);
    });

    it('answers a server question from servers_list', async () => {
        const mcp = fakeMcp();
        const answer = await fallbackAnswer('Welche Server gibt es?', { mcp, baseUrl: 'https://lan.example' });

        assert.deepEqual(mcp.calls, [['servers_list', {}]]);
        assert.match(answer, /UT2004 Hauptserver/);
        assert.match(answer, /ut2004:\/\/10\.23\.0\.40:7777/);
        assert.doesNotMatch(answer, /\[object Object\]/);
    });

    it('lists alternatives instead of guessing when several recipes match', async () => {
        const mcp = fakeMcp({
            recipes_list: () => ({
                recipes: [recipeSummary, { ...recipeSummary, slug: 'ut2004-demo', title: 'UT2004 Demo' }],
            }),
        });
        const answer = await fallbackAnswer('Welche Spiele gibt es?', { mcp, baseUrl: 'https://lan.example' });

        assert.match(answer, /Diese Spiele kann ich installieren/);
        assert.match(answer, /unreal-tournament-2004/);
        assert.match(answer, /ut2004-demo/);
        assert.doesNotMatch(answer, /Installiere Unreal/);
    });

    it('does not invent a recipe when nothing matches', async () => {
        const mcp = fakeMcp({ recipes_list: () => ({ recipes: [] }) });
        const answer = await fallbackAnswer('Installiere Half-Life 3', { mcp, baseUrl: 'https://lan.example' });

        assert.match(answer, /kein passendes Spiel/);
        assert.match(answer, /\/games/);
    });
});

describe('MCP JSON-RPC client', () => {
    it('parses plain JSON and SSE payloads', () => {
        assert.deepEqual(parseJsonRpcPayload('{"jsonrpc":"2.0","id":1,"result":{}}'), [{ jsonrpc: '2.0', id: 1, result: {} }]);
        assert.deepEqual(
            parseJsonRpcPayload('event: message\ndata: {"jsonrpc":"2.0","id":2,"result":{"ok":true}}\n\n'),
            [{ jsonrpc: '2.0', id: 2, result: { ok: true } }],
        );
    });

    it('prefers structuredContent and falls back to a JSON text block', () => {
        assert.deepEqual(normalizeToolResult({ structuredContent: { recipes: [] } }).structured, { recipes: [] });
        assert.deepEqual(normalizeToolResult({ content: [{ type: 'text', text: '{"servers":[]}' }] }).structured, { servers: [] });
        assert.equal(normalizeToolResult({ isError: true, content: [{ type: 'text', text: 'Not found.' }] }).text, 'Not found.');
    });

    it('initializes once and sends bearer auth', async () => {
        const requests = [];
        const fetchImpl = async (url, init) => {
            requests.push({ url, headers: init.headers, body: JSON.parse(init.body) });

            return new Response(JSON.stringify({ jsonrpc: '2.0', id: requests.length, result: {} }), { status: 200 });
        };
        const client = new McpJsonRpcClient({ baseUrl: 'https://lan.example', token: 'lmcp_test', fetchImpl });

        await client.listTools({ perPage: 100 });
        await client.listTools({ perPage: 100 });

        assert.equal(requests[0].url, 'https://lan.example/mcp');
        assert.equal(requests[0].headers.Authorization, 'Bearer lmcp_test');
        assert.equal(requests[0].body.method, 'initialize');
        assert.deepEqual(requests.at(-1).body.params, { per_page: 100 });
        assert.equal(requests.filter((request) => request.body.method === 'initialize').length, 1);
    });

    it('accepts a full per_page page and returns every tool', async () => {
        const names = Array.from({ length: 25 }, (_, index) => `tool_${index}`);
        const fetchImpl = async (_url, init) => {
            const body = JSON.parse(init.body);
            const result = body.method === 'initialize'
                ? {}
                : { tools: names.map((name) => ({ name })) , nextCursor: undefined };

            return new Response(JSON.stringify({ jsonrpc: '2.0', id: body.id, result }), { status: 200 });
        };
        const client = new McpJsonRpcClient({ baseUrl: 'https://lan.example', token: 't', fetchImpl });
        const result = await client.listTools({ perPage: 100 });

        assert.equal(result.tools.length, 25);
        assert.equal(result.nextCursor, undefined);
    });
});

describe('agent orchestration', () => {
    it('falls back when pi cannot start', async () => {
        const agent = createAgent({
            env: { LANOMAT_URL: 'https://lan.example', LANOMAT_TOKEN: 'lmcp_test', PI_CODING_AGENT_DIR: await tempDir() },
            fetchImpl: fakeFetch(),
            spawnImpl: () => {
                const error = new Error('spawn pi ENOENT');

                throw error;
            },
        });
        const answer = await agent.answer({ text: 'Installiere UT2004 unter Linux', author: 'Rene' });

        assert.equal(answer.source, 'fallback');
        assert.match(answer.text, /Installiere Unreal Tournament 2004/);
        assert.match(answer.text, /lanomat:\/\/install\/unreal-tournament-2004/);
    });

    it('answers from pi when it succeeds', async () => {
        const calls = [];
        const agent = createAgent({
            env: { LANOMAT_URL: 'https://lan.example', LANOMAT_TOKEN: 'lmcp_test', PI_CODING_AGENT_DIR: await tempDir() },
            spawnImpl: (binary, argv) => {
                calls.push([binary, argv]);

                const child = new EventEmitter();

                child.stdout = new EventEmitter();
                child.stderr = new EventEmitter();
                child.kill = () => {};
                setImmediate(() => {
                    child.stdout.emit('data', Buffer.from('Hallo von pi\n'));
                    child.emit('close', 0);
                });

                return child;
            },
        });
        const answer = await agent.answer({ text: 'Hi' });

        assert.equal(answer.source, 'pi');
        assert.equal(answer.text, 'Hallo von pi');
        assert.equal(calls[0][0], 'pi');
    });

    it('gives a clear message when no token is configured', async () => {
        const agent = createAgent({ env: { LANOMAT_URL: 'https://lan.example' } });
        const answer = await agent.answer({ text: 'Hi' });

        assert.equal(answer.source, 'error');
        assert.match(answer.text, /LANOMAT_TOKEN/);
        assert.match(answer.text, /\/games/);
    });
});

describe('rate limiter', () => {
    it('allows the configured number of questions per window', () => {
        let now = 0;
        const limiter = new RateLimiter({ limit: 2, windowMs: 1000, clock: () => now });

        assert.equal(limiter.take('u').allowed, true);
        assert.equal(limiter.take('u').allowed, true);
        assert.equal(limiter.take('u').allowed, false);
        assert.equal(limiter.take('other').allowed, true);

        now = 1001;
        assert.equal(limiter.take('u').allowed, true);
    });
});
