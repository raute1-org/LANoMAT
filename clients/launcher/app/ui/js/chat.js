// Chat: the launcher's arcade front desk. Deterministic intents only (games,
// servers), a quick demo type-in that a click can skip, and cards with one
// primary action. Nothing here invents data; every card comes from the shell.
import * as install from './install.js';
import * as ready from './ready.js';
import { gameCard } from './library.js';
import { state } from './state.js';
import { SERVERS_PATH, S } from './strings.js';
import {
    $,
    announce,
    call,
    el,
    focusNode,
    httpStatus,
    isAuthError,
    setHidden,
    setText,
    toast,
} from './util.js';

let onSignedOut = () => {};
let onStatus = () => {};
let lastIntent = null;
let requestId = 0;
let demoTimer = 0;

export function init({ signedOut, status }) {
    onSignedOut = signedOut;
    onStatus = status;

    $('chat-form').addEventListener('submit', (event) => {
        event.preventDefault();
        demoTimer += 1;
        askFromInput();
    });
    $('chat-input').addEventListener('input', () => {
        // A real keystroke wins over the demo.
        demoTimer += 1;
    });
    $('chat-clear').addEventListener('click', reset);

    for (const chip of document.querySelectorAll('#chat-suggestions [data-intent]')) {
        chip.addEventListener('click', () => {
            const text =
                chip.dataset.intent === 'servers'
                    ? S.chat.suggestionServers
                    : S.chat.suggestionGames;

            demoAsk(text);
        });
    }
}

export function show() {
    paintServerName();

    if (!$('chat-log').firstElementChild) {
        reset({ focus: false });
    }
}

export function retry() {
    if (lastIntent) {
        runIntent(lastIntent);
    }
}

/** Empty transcript for the next player; keeps focus where it is. */
export function clear() {
    reset({ focus: false });
}

function paintServerName() {
    const name = String(state.status?.server?.name || '').trim();

    setText('chat-server-name', name);
    setHidden('chat-server', !name);
}

function paintHint(show) {
    setHidden('chat-hint', !show);
    setText('chat-hint', `${S.chat.intro} ${S.chat.hint}`);
}

function reset({ focus = true } = {}) {
    requestId += 1;
    demoTimer += 1;
    lastIntent = null;

    const log = $('chat-log');

    log.replaceChildren();

    const greeting = assistant();
    greeting.body.append(
        el('p', { text: S.chat.greeting(state.status?.user?.name || '') }),
        el('p', { class: 'small muted', text: S.chat.intro }),
    );

    paintHint(true);
    $('chat-input').value = '';
    if (focus) {
        focusNode($('chat-input'));
    }
}

function assistant() {
    const node = el(
        'div',
        { class: 'msg assistant' },
        el('div', { class: 'msg-who', text: S.chat.assistant }),
    );
    const bubble = el('div', { class: 'bubble stack-sm' });

    node.append(bubble);

    $('chat-log').append(node);
    scrollChat();

    return { node, body: bubble };
}

function user(text) {
    const node = el(
        'div',
        { class: 'msg user' },
        el('div', { class: 'msg-who', text: S.chat.you }),
        el('div', { class: 'bubble' }, el('p', { text })),
    );

    $('chat-log').append(node);
    scrollChat();
}

function scrollChat() {
    const main = $('main');

    if (main) {
        main.scrollTop = main.scrollHeight;
    }
}

function askFromInput() {
    const input = $('chat-input');
    const text = input.value.trim();

    if (!text) {
        input.focus();

        return;
    }

    input.value = '';
    paintHint(false);
    user(text);

    const intent = intentOf(text);

    if (intent === null) {
        const answer = assistant();

        answer.body.append(el('p', { text: S.chat.unknown }));
        announce(S.chat.unknown);

        return;
    }

    runIntent(intent);
}

/**
 * Deterministic, local intent recognition: "server" wins over "spiel" when a
 * question mentions both, because the server answer also names the games.
 */
export function intentOf(text) {
    const value = String(text ?? '')
        .toLowerCase()
        .replace(/[^\p{L}\p{N}\s]/gu, ' ');

    if (/\b(server|servers|adresse|ip|beitreten|läuft|laufen|online)\b/u.test(value)) {
        return 'servers';
    }

    if (/\b(spiel|spiele|game|games|install|installieren|bibliothek|starten)\b/u.test(value)) {
        return 'games';
    }

    return null;
}

async function runIntent(intent) {
    lastIntent = intent;

    const id = ++requestId;
    const answer = assistant();
    const thinking = el('p', { class: 'small muted', text: S.chat.thinking });

    answer.body.append(el('div', { class: 'skeleton skeleton-line' }), thinking);

    const result =
        intent === 'servers' ? await askServers(answer.body) : await askGames(answer.body);

    if (id !== requestId) {
        answer.node.remove();

        return;
    }

    thinking.remove();

    if (result) {
        announce(result);
    }
}

async function askGames(body) {
    const [gamesResult, statusResult] = await Promise.all([
        call('list_games'),
        call('get_status'),
    ]);

    body.replaceChildren();

    if (!statusResult.ok) {
        errorState(body, statusResult.error);

        return null;
    }

    onStatus(statusResult.value);

    if (!statusResult.value?.paired) {
        authState(body);

        return null;
    }

    if (!gamesResult.ok) {
        errorState(body, gamesResult.error);

        return null;
    }

    state.games = Array.isArray(gamesResult.value) ? gamesResult.value : [];

    if (state.games.length === 0) {
        body.append(
            el('p', { class: 'strong', text: S.chat.gamesEmptyTitle }),
            el('p', { class: 'small muted', text: S.chat.gamesEmptyText }),
        );

        return S.chat.gamesEmptyTitle;
    }

    const summary = S.chat.gamesSummary(state.games.length);

    body.append(el('p', { class: 'strong', text: summary }));

    const cards = el('div', { class: 'chat-cards' });
    const heroSlug = state.games.find(
        (recipe) => recipe && recipe.launcher_supported,
    )?.slug;

    for (const recipe of state.games) {
        cards.append(gameCard(recipe, { hero: recipe.slug === heroSlug }));
    }

    body.append(cards);

    return summary;
}

async function askServers(body) {
    const result = await call('list_servers');

    body.replaceChildren();

    if (!result.ok) {
        errorState(body, result.error);

        return null;
    }

    const list = result.value || {};
    const servers = Array.isArray(list.servers) ? list.servers : [];

    state.servers = { events: list.events || [], servers };

    if (servers.length === 0) {
        body.append(
            el('p', { class: 'strong', text: S.chat.serversEmptyTitle }),
            el('p', { class: 'small muted', text: S.chat.serversEmptyText }),
        );

        return S.chat.serversEmptyTitle;
    }

    const games = new Set(
        servers.map((server) => server.game).filter((game) => Boolean(game)),
    );
    const summary = S.chat.serversSummary(servers.length, games.size);

    body.append(el('p', { class: 'strong', text: summary }));

    const cards = el('div', { class: 'server-grid' });

    for (const server of servers) {
        cards.append(serverCard(server));
    }

    body.append(cards);

    return summary;
}

function errorState(body, error) {
    const text = failureText(error);

    body.append(
        el('p', { class: 'strong', text: S.chat.errorTitle }),
        el('p', { class: 'small muted', text }),
    );

    const actions = el('div', { class: 'row wrap' });

    if (isAuthError(error)) {
        actions.append(
            el('button', {
                class: 'btn primary',
                type: 'button',
                text: S.chat.reconnect,
                onclick: () => onSignedOut(),
            }),
        );
    } else {
        actions.append(
            el('button', {
                class: 'btn',
                type: 'button',
                text: S.chat.retry,
                onclick: () => retry(),
            }),
        );
    }

    body.append(actions);
    announce(text);
}

function authState(body) {
    body.append(
        el('p', { class: 'strong', text: S.chat.errorTitle }),
        el('p', { class: 'small muted', text: S.chat.authText }),
        el('div', {
            class: 'row wrap',
        }, el('button', {
            class: 'btn primary',
            type: 'button',
            text: S.chat.reconnect,
            onclick: () => onSignedOut(),
        })),
    );
}

function failureText(error) {
    if (!error) {
        return S.chat.errorTitle;
    }

    if (error.kind === 'network') {
        return S.connect.networkText;
    }

    if (isAuthError(error)) {
        return S.chat.authText;
    }

    const status = httpStatus(error.raw);

    return status ? S.httpError(status) : error.raw || S.chat.errorTitle;
}

function serverCard(server) {
    const node = $('tpl-server').content.firstElementChild.cloneNode(true);
    const status = String(server.status || '');
    const query = server.query || null;
    const joinUrl = joinUrlOf(server);
    const page = pageUrlOf(server);

    node.dataset.server = String(server.id ?? '');

    node.querySelector('[data-field="title"]').textContent =
        server.title || server.game || S.chat.unknownStatus(status);

    const game = node.querySelector('[data-field="game"]');

    if (server.game) {
        game.textContent = server.game;
        game.hidden = false;
    }

    const event = node.querySelector('[data-field="event"]');

    if (server.permanent) {
        event.textContent = S.chat.permanent;
        event.hidden = false;
    } else if (server.event?.name) {
        event.textContent = S.chat.event(server.event.name);
        event.hidden = false;
    }

    const badge = node.querySelector('[data-field="status"]');

    badge.querySelector('[data-field="status-text"]').textContent =
        S.chat.status[status] || S.chat.unknownStatus(status);
    badge.querySelector('.dot').classList.add(statusDot(status));

    const meta = node.querySelector('[data-field="meta"]');
    const lines = [];

    if (query) {
        lines.push(S.chat.players(query.players ?? 0, query.maxPlayers ?? 0));

        if (query.map) {
            lines.push(S.chat.map(query.map));
        }
    }

    if (server.description) {
        lines.push(server.description);
    }

    if (lines.length > 0) {
        meta.textContent = lines.join(' · ');
    } else {
        meta.hidden = true;
    }

    const address = node.querySelector('[data-field="address"]');
    const addressText =
        server.connectString ||
        [server.address, server.port].filter((part) => part !== null && part !== undefined && part !== '').join(':');

    address.textContent = addressText || S.chat.noJoin;

    const action = node.querySelector('[data-action="join"]');

    if (joinUrl || page) {
        action.textContent = joinUrl ? S.chat.join : S.chat.page;
        action.classList.toggle('primary', Boolean(joinUrl));
        action.addEventListener('click', () => openServer(joinUrl || page));
    } else {
        action.hidden = true;
    }

    return node;
}

function statusDot(status) {
    if (status === 'ready') {
        return 'ok';
    }

    if (status === 'not_responding' || status === 'provisioning' || status === 'pending') {
        return 'warn';
    }

    return 'down';
}

function joinUrlOf(server) {
    if (server.status !== 'ready' && server.status !== 'not_responding') {
        return null;
    }

    const options = Array.isArray(server.joinOptions) ? server.joinOptions : [];
    const option = options.find((candidate) => isAllowedUrl(candidate?.url));

    if (option) {
        return option.url;
    }

    if (server.connectIsLink && isAllowedUrl(server.connectString)) {
        return server.connectString;
    }

    return null;
}

function isAllowedUrl(raw) {
    const value = String(raw ?? '').trim();

    if (!/^(https?|steam|ut2004):\/\//i.test(value)) {
        return false;
    }

    return !/[\s\x00-\x1f\x7f]/.test(value);
}

function pageUrlOf(server) {
    const base = serverBase();

    if (!base) {
        return null;
    }

    if (server.permanent) {
        return `${base}${SERVERS_PATH}`;
    }

    const slug = String(server.event?.slug || '');

    return /^[a-z0-9-]{1,120}$/i.test(slug)
        ? `${base}/events/${encodeURIComponent(slug)}/servers`
        : null;
}

function serverBase() {
    const raw = String(state.url || state.status?.server?.url || '').trim();
    const trimmed = raw.replace(/\/+$/, '');

    return /^https?:\/\/[^/\s]+(\/[^\s]*)?$/i.test(trimmed) ? trimmed : null;
}

async function openServer(url) {
    if (!url) {
        return;
    }

    const result = await call('open_url', { url });

    if (!result.ok) {
        toast(S.chat.noJoin, 'error');
    }
}

function demoAsk(text) {
    const input = $('chat-input');
    const token = ++demoTimer;
    const reduced =
        typeof window.matchMedia === 'function' &&
        window.matchMedia('(prefers-reduced-motion: reduce)').matches;

    input.focus();

    if (reduced) {
        input.value = '';
        submitDemo(text, token);

        return;
    }

    demoClear(input, token)
        .then(() => demoType(input, text, token))
        .then(() => {
            if (token === demoTimer) {
                input.value = '';
                submitDemo(text, token);
            }
        })
        .catch(() => {});
}

function submitDemo(text, token) {
    if (token !== demoTimer) {
        return;
    }

    paintHint(false);
    user(text);

    const intent = intentOf(text);

    if (intent) {
        runIntent(intent);
    }
}

async function demoClear(input, token) {
    while (input.value.length > 0 && token === demoTimer) {
        input.value = input.value.slice(0, -1);
        await wait(16);
    }
}

async function demoType(input, text, token) {
    for (const character of text) {
        if (token !== demoTimer) {
            return;
        }

        input.value += character;
        await wait(24);
    }
}

function wait(ms) {
    return new Promise((resolve) => window.setTimeout(resolve, ms));
}
