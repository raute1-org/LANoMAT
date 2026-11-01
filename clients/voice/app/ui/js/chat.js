// Text chat next to voice: history per room, send with Enter, live messages through the
// chat events, mention highlighting, moderator delete and the unread counts.
import { setBadge } from './nav.js';
import { avatar } from './shared.js';
import { S } from './strings.js';
import {
    button,
    call,
    clear,
    clock,
    el,
    emit,
    emptyState,
    errorState,
    listen,
    mentions,
    on,
    plainMessage,
    skeleton,
    state,
    toast,
} from './util.js';

const MAX = 500;
const rooms = new Map(); // roomId -> {messages, nextBefore, phase, error, unread, mentions}
let viewed = null;
let box = null;
let listEl = null;
let inputEl = null;
let sendBtn = null;
let counterEl = null;
let hintEl = null;
let statusEl = null;
let titleEl = null;
let bodyEl = null;
let subscribed = '';
let cooldownTimer = null;

const room = (id) => {
    if (!rooms.has(id)) {
        rooms.set(id, {
            messages: [],
            nextBefore: null,
            phase: 'idle',
            error: null,
            unread: 0,
            mentions: 0,
        });
    }

    return rooms.get(id);
};

export const unreadIn = (id) => (rooms.has(id) ? rooms.get(id).unread : 0);
export const viewedRoom = () => viewed;

const canModerate = () =>
    Boolean(state.status && ['admin', 'orga'].includes(state.status.role));

function updateBadge() {
    let n = 0;

    for (const r of rooms.values()) {
        n += r.mentions;
    }

    setBadge('sprache', n);
    emit('chat-unread', n);
}

function isWatching(id) {
    return (
        viewed === id &&
        state.tab === 'sprache' &&
        document.visibilityState === 'visible'
    );
}

export function initChat(container) {
    box = container;
    titleEl = el('h2');
    statusEl = el('span', { class: 'small muted', role: 'status' });
    bodyEl = el('div', { class: 'chat-body' });
    listEl = el('div', {
        class: 'chat-list',
        role: 'log',
        'aria-live': 'polite',
        'aria-label': S.chat.log,
        tabindex: '0',
    });
    inputEl = el('textarea', {
        class: 'chat-input',
        rows: '1',
        maxlength: String(MAX),
        placeholder: S.chat.placeholder,
        'aria-label': S.chat.inputLabel,
        spellcheck: 'true',
    });
    counterEl = el('span', {
        class: 'small muted counter',
        'aria-hidden': 'true',
    });
    hintEl = el('div', { class: 'small chat-hint', role: 'status' });
    sendBtn = el('button', {
        class: 'btn primary',
        type: 'button',
        text: S.chat.send,
        onclick: send,
    });
    box.append(
        el(
            'div',
            { class: 'chat-panel' },
            el('div', { class: 'row between' }, titleEl, statusEl),
            bodyEl,
            el(
                'div',
                { class: 'composer' },
                el('div', { class: 'row end-aligned' }, inputEl, sendBtn),
                el('div', { class: 'row between' }, hintEl, counterEl),
            ),
        ),
    );

    inputEl.addEventListener('keydown', (e) => {
        if (e.key === 'Enter' && !e.shiftKey && !e.isComposing) {
            e.preventDefault();
            send();
        }
    });
    inputEl.addEventListener('input', () => {
        autosize();
        paintCounter();
    });

    listen('chat-event', (e) => onChatEvent(e.payload));
    on('rooms', (list) => subscribe(list));
    on('room-view', () => {
        const v = state.view;

        if (v && v.room && v.phase !== 'idle' && viewed === null) {
            viewRoom(v.room.id);
        }
    });
    on('tab', () => {
        if (state.tab === 'sprache' && viewed !== null) {
            markRead(viewed);
        }
    });
    document.addEventListener('visibilitychange', () => {
        if (isWatching(viewed)) {
            markRead(viewed);
        }
    });
    paint();
}

async function subscribe(list) {
    const ids = list.map((r) => r.id).sort((a, b) => a - b);
    const key = ids.join(',');

    if (key === subscribed) {
        return;
    }

    subscribed = key;
    await call('chat_subscribe', { roomIds: ids });
}

function autosize() {
    inputEl.style.height = 'auto';
    inputEl.style.height = `${Math.min(inputEl.scrollHeight, 120)}px`;
}

function paintCounter() {
    const n = inputEl.value.length;
    counterEl.textContent = n > 400 ? S.chat.counter(n, MAX) : '';
    counterEl.classList.toggle('warn-text', n >= MAX - 20);
}

export async function viewRoom(id) {
    viewed = id;
    const r = room(id);
    markRead(id);
    paint();
    emit('chat-unread'); // the room list marks the room whose chat is open

    if (r.phase === 'idle' || r.phase === 'error') {
        await loadHistory(id);
    }
}

function markRead(id) {
    if (!isWatching(id)) {
        return;
    }

    const r = room(id);

    if (r.unread || r.mentions) {
        r.unread = 0;
        r.mentions = 0;
        updateBadge();
    }
}

async function loadHistory(id, before) {
    const r = room(id);

    if (!before) {
        r.phase = 'loading';
        paint();
    }

    const res = await call('chat_history', { roomId: id, before });

    if (!res.ok) {
        if (!before) {
            r.phase = 'error';
            r.error = res.error;
        } else {
            toast(plainMessage(res.error), 'error');
        }

        paint();

        return;
    }

    const seen = new Set(r.messages.map((m) => m.id));
    const older = res.value.messages.filter((m) => !seen.has(m.id));
    r.messages = [...older, ...r.messages];
    r.messages.sort((a, b) => a.id - b.id);
    r.nextBefore = res.value.next_before;
    r.phase = 'ready';
    paint(before ? 'keep' : 'bottom');
}

function onChatEvent(ev) {
    if (!ev) {
        return;
    }

    if (ev.type === 'channels_changed') {
        emit('rooms-changed');
    } else if (ev.type === 'status') {
        state.chatStatus = ev.state;
        paintStatus();
    } else if (ev.type === 'message_posted') {
        const r = room(ev.room_id);

        if (r.messages.some((m) => m.id === ev.message.id)) {
            return;
        }

        r.messages.push(ev.message);
        const mine =
            state.status && ev.message.author.name === state.status.user_name;

        if (r.phase === 'idle') {
            // Not loaded yet: the history fetch will bring it, do not show a half list.
            r.messages = [];
        }

        if (!mine && !isWatching(ev.room_id)) {
            r.unread += 1;

            if (
                mentions(
                    ev.message.body,
                    state.status && state.status.user_name,
                )
            ) {
                r.mentions += 1;
            }

            updateBadge();
        }

        if (viewed === ev.room_id) {
            paint('bottom-if-near');
        }
    } else if (ev.type === 'message_deleted') {
        const r = room(ev.room_id);
        r.messages = r.messages.filter((m) => m.id !== ev.message_id);

        if (viewed === ev.room_id) {
            paint('keep');
        }
    }
}

function paintStatus() {
    const s = state.chatStatus;
    statusEl.textContent = S.chat.status[s] || '';
    statusEl.hidden = s === 'live' || !S.chat.status[s];
}

function paint(scroll) {
    if (!box) {
        return;
    }

    paintStatus();
    const wasNearBottom =
        listEl.scrollHeight - listEl.scrollTop - listEl.clientHeight < 80;
    const prevHeight = listEl.scrollHeight;
    const prevTop = listEl.scrollTop;
    const r = viewed === null ? null : room(viewed);
    const info = state.rooms.find((x) => x.id === viewed);
    titleEl.textContent = info ? S.chat.titleIn(info.name) : S.chat.title;
    clear(bodyEl);
    const composing = Boolean(r) && r.phase === 'ready';
    inputEl.disabled = !composing;
    sendBtn.disabled = !composing;

    if (!r) {
        bodyEl.append(emptyState(S.chat.noRoom, S.chat.noRoomHint));

        return;
    }

    if (r.phase === 'loading' || r.phase === 'idle') {
        bodyEl.append(skeleton(4));

        return;
    }

    if (r.phase === 'error') {
        bodyEl.append(
            errorState(r.error, () => loadHistory(viewed), S.chat.loadFailed),
        );

        return;
    }

    if (!r.messages.length) {
        bodyEl.append(emptyState(S.chat.empty, S.chat.emptyHint));

        return;
    }

    clear(listEl);

    if (r.nextBefore) {
        listEl.append(
            el(
                'div',
                { class: 'row center' },
                button(S.chat.older, {
                    kind: 'ghost',
                    onclick: () => loadHistory(viewed, r.nextBefore),
                }),
            ),
        );
    }

    const me = state.status && state.status.user_name;

    for (const m of r.messages) {
        const isMe = m.author.name === me;
        const hit = !isMe && mentions(m.body, me);
        listEl.append(
            el(
                'div',
                {
                    class:
                        'msg' + (isMe ? ' mine' : '') + (hit ? ' mention' : ''),
                    'data-id': m.id,
                },
                el(
                    'div',
                    { class: 'msg-head' },
                    m.author.avatar_url
                        ? avatar(m.author.name, {
                              tiny: true,
                              src: m.author.avatar_url,
                          })
                        : null,
                    el('span', {
                        class: 'msg-author',
                        text: isMe ? S.chat.you : m.author.name,
                    }),
                    el('span', {
                        class: 'msg-time mono',
                        text: clock(m.created_at),
                    }),
                    hit
                        ? el('span', {
                              class: 'chip live',
                              text: S.chat.mentionsYou,
                          })
                        : null,
                    canModerate()
                        ? el('button', {
                              class: 'btn ghost small-btn',
                              type: 'button',
                              text: S.chat.remove,
                              'aria-label': S.chat.removeAria(m.author.name),
                              onclick: () => removeMessage(m),
                          })
                        : null,
                ),
                el('div', { class: 'msg-body', text: m.body }),
            ),
        );
    }

    bodyEl.append(listEl);

    if (
        scroll === 'bottom' ||
        (scroll === 'bottom-if-near' && wasNearBottom) ||
        scroll === undefined
    ) {
        listEl.scrollTop = listEl.scrollHeight;
    } else if (scroll === 'keep') {
        listEl.scrollTop = prevTop + (listEl.scrollHeight - prevHeight);
    }
}

async function removeMessage(m) {
    const res = await call('chat_delete', { messageId: m.id });

    if (!res.ok) {
        toast(plainMessage(res.error), 'error');

        return;
    }

    const r = room(viewed);
    r.messages = r.messages.filter((x) => x.id !== m.id);
    paint('keep');
}

function setHint(text, tone = '') {
    hintEl.textContent = text;
    hintEl.className = `small chat-hint ${tone}`.trim();
}

function cooldown(secs) {
    clearTimeout(cooldownTimer);
    sendBtn.disabled = true;
    setHint(S.errors.rateLimitedIn(secs), 'calm');
    cooldownTimer = setTimeout(
        () => {
            sendBtn.disabled = false;
            setHint('');
        },
        Math.max(1, secs) * 1000,
    );
}

async function send() {
    const body = inputEl.value.trim();

    if (!body || viewed === null || sendBtn.disabled) {
        return;
    }

    if (body.length > MAX) {
        setHint(S.chat.tooLong(MAX), 'calm');

        return;
    }

    sendBtn.disabled = true;
    const res = await call('chat_post', { roomId: viewed, body });
    sendBtn.disabled = false;

    if (!res.ok) {
        if (res.error.kind === 'rate_limited') {
            cooldown(res.error.retry_after_secs || 5);
        } else {
            setHint(plainMessage(res.error), 'calm');
        }

        return;
    }

    setHint('');
    inputEl.value = '';
    autosize();
    paintCounter();
    const r = room(viewed);
    const msg = res.value && res.value.message ? res.value.message : res.value;

    // The live event may already have added it: de-duplicate by id.
    if (msg && msg.id && !r.messages.some((m) => m.id === msg.id)) {
        r.messages.push(msg);
    }

    paint('bottom');
    inputEl.focus();
}
