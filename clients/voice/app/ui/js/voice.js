// Sprache: voice rooms, the live room panel with push-to-talk, and the voice dock that keeps
// talking and muting reachable from every other tab. The text chat sits beside it (chat.js).
import { initChat, unreadIn, viewRoom, viewedRoom } from './chat.js';
import { go, pageHeader, setWho } from './nav.js';
import { peopleOn } from './optional.js';
import * as people from './people.js';
import { S } from './strings.js';
import {
    button,
    call,
    chip,
    clear,
    dot,
    el,
    emit,
    emptyState,
    errorState,
    listen,
    on,
    plainMessage,
    say,
    skeleton,
    state,
    toast,
} from './util.js';

let root = null;
let roomsBox = null;
let panelBox = null;
let peopleBox = null;
let viewBox = null;
let colsBox = null;
const rooms = { phase: 'loading', error: null };
let joining = null;
let view = 'rooms'; // 'rooms' | 'people' (where is everybody), the latter only when the server has it
let peopleFilter = 'all';

export async function show(opts = {}) {
    if (opts.view === 'people' || opts.view === 'rooms') {
        view = opts.view;
        peopleFilter = opts.filter || 'all';
    }

    syncView(true);
    await refreshRooms();
}

export function focus(opts = {}) {
    if (opts.view === 'people' || opts.view === 'rooms') {
        view = opts.view;
        peopleFilter = opts.filter || 'all';
        syncView(true);
    }
}

export function hide() {
    people.unmount();
}

// The switch between the rooms and "Personen"; its content is mounted when the view changes.
function paintViewSwitch() {
    const available = peopleOn();
    viewBox.hidden = !available;
    clear(viewBox);

    for (const [id, text] of [
        ['rooms', S.people.rooms],
        ['people', S.people.people],
    ]) {
        viewBox.append(
            el('button', {
                class: 'seg-btn',
                type: 'button',
                text,
                'aria-pressed': String(view === id),
                onclick: () => {
                    view = id;
                    peopleFilter = 'all';
                    syncView(true);
                },
            }),
        );
    }
}

function syncView(remount) {
    if (!viewBox) {
        return;
    }

    if (!peopleOn()) {
        view = 'rooms';
    }

    paintViewSwitch();
    roomsBox.hidden = view !== 'rooms';
    peopleBox.hidden = view !== 'people';
    colsBox.classList.toggle('people-view', view === 'people');

    if (remount) {
        people.unmount();
        clear(peopleBox);

        if (view === 'people') {
            people.mount(peopleBox, { filter: peopleFilter });
        }
    }
}

export function init(section) {
    root = section;
    roomsBox = el('div', { class: 'stack' });
    panelBox = el('div', {});
    peopleBox = el('div', { class: 'stack', hidden: true });
    viewBox = el('div', {
        class: 'seg',
        role: 'group',
        'aria-label': S.people.views,
        hidden: true,
    });
    const chatBox = el('div', { class: 'chat-col' });
    colsBox = el(
        'div',
        { class: 'cols' },
        el(
            'div',
            { class: 'stack col-voice' },
            panelBox,
            viewBox,
            roomsBox,
            peopleBox,
        ),
        chatBox,
    );
    root.append(pageHeader('sprache'), colsBox);
    initChat(chatBox);
    renderRooms();
    renderPanel();

    listen('room-updated', (e) => {
        state.view = e.payload;
        renderPanel();
        renderRooms();
        renderDock();
        emit('room-view');
    });
    on('chat-unread', renderRooms);
    on('rooms-changed', () => refreshRooms());
    on('tab', renderDock);
    on('screen', renderDock);
}

export const inRoom = () =>
    Boolean(state.view && state.view.room && state.view.phase !== 'idle');

export async function refreshRooms() {
    if (rooms.phase !== 'ready') {
        rooms.phase = 'loading';
        renderRooms();
    }

    const [view, r] = await Promise.all([
        call('get_room_view'),
        call('list_rooms'),
    ]);

    if (view.ok) {
        state.view = view.value;
    }

    if (r.ok) {
        state.rooms = r.value;
        rooms.phase = r.value.length ? 'ready' : 'empty';
        rooms.error = null;
        emit('rooms', r.value);
    } else {
        rooms.phase = 'error';
        rooms.error = r.error;
        setWho('down');
    }

    renderRooms();
    renderPanel();
    renderDock();
}

function renderRooms() {
    if (!roomsBox) {
        return;
    }

    clear(roomsBox);
    roomsBox.append(
        el(
            'div',
            { class: 'row between' },
            el('h2', { text: S.voice.rooms }),
            button(S.reload, { onclick: refreshRooms }),
        ),
    );

    if (rooms.phase === 'loading') {
        roomsBox.append(skeleton(3));

        return;
    }

    if (rooms.phase === 'error') {
        roomsBox.append(
            errorState(rooms.error, refreshRooms, S.voice.roomsFailed),
        );

        return;
    }

    if (rooms.phase === 'empty') {
        roomsBox.append(emptyState(S.voice.noRooms, S.voice.noRoomsHint));

        return;
    }

    const currentId = state.view && state.view.room ? state.view.room.id : null;
    const groups = [
        [S.voice.general, state.rooms.filter((r) => r.kind !== 'match')],
        [S.voice.matchRooms, state.rooms.filter((r) => r.kind === 'match')],
    ];

    for (const [title, list] of groups) {
        if (!list.length) {
            continue;
        }

        const box = el(
            'div',
            { class: 'stack-sm', role: 'group', 'aria-label': title },
            el('div', { class: 'group-title', text: title }),
        );

        for (const room of list) {
            const current = room.id === currentId && inRoom();
            const unread = unreadIn(room.id);
            const viewed = viewedRoom() === room.id;
            const joinBtn = el('button', {
                class: 'btn' + (current ? '' : ' primary-soft'),
                type: 'button',
                'aria-label': `${current ? S.voice.inRoom : S.voice.join}: ${room.name}`,
                text:
                    joining === room.id
                        ? S.voice.joining
                        : current
                          ? S.voice.inRoom
                          : S.voice.join,
                disabled: joining === room.id || current,
                onclick: () => joinRoom(room.id),
            });
            const chatBtn = el(
                'button',
                {
                    class: 'btn ghost' + (viewed ? ' active' : ''),
                    type: 'button',
                    'aria-pressed': String(viewed),
                    'aria-label': `${S.chat.open}: ${room.name}`,
                    onclick: () => viewRoom(room.id),
                },
                el('span', { text: S.chat.open }),
                unread > 0
                    ? el('span', {
                          class: 'badge inline',
                          text: String(unread),
                      })
                    : null,
            );
            box.append(
                el(
                    'div',
                    { class: 'room-row' + (current ? ' current' : '') },
                    el(
                        'div',
                        { class: 'grow' },
                        el('div', { class: 'name', text: room.name }),
                        room.description
                            ? el('div', {
                                  class: 'small muted',
                                  text: room.description,
                              })
                            : null,
                    ),
                    chatBtn,
                    joinBtn,
                ),
            );
        }

        roomsBox.append(box);
    }
}

export async function joinRoom(roomId, { toTab = false } = {}) {
    joining = roomId;
    renderRooms();
    const r = await call('join_room', { roomId });
    joining = null;

    if (!r.ok) {
        renderRooms();
        const msg = plainMessage(r.error);
        state.roomError = msg;
        renderPanel();
        toast(msg, 'error');

        return false;
    }

    state.roomError = null;
    // The room-updated events race with this response: ask for the authoritative snapshot.
    const v = await call('get_room_view');
    state.view = v.ok ? v.value : r.value;
    renderRooms();
    renderPanel();
    renderDock();
    emit('room-view');
    const name = state.view && state.view.room ? state.view.room.name : '';
    toast(S.voice.joined(name));
    viewRoom(roomId);

    if (toTab) {
        await go('sprache');
        const p = document.getElementById('ptt');

        if (p) {
            p.focus();
        }
    }

    return true;
}

export async function leaveRoom() {
    await call('leave_room');
    const v = await call('get_room_view');

    if (v.ok) {
        state.view = v.value;
    }

    renderRooms();
    renderPanel();
    renderDock();
    emit('room-view');
    say(S.voice.left);
}

// ---- live room panel ----

const PHASE = {
    connecting: ['warn', S.voice.phase.connecting],
    connected: ['ok', S.voice.phase.connected],
    reconnecting: ['warn', S.voice.phase.reconnecting],
    failed: ['down', S.voice.phase.failed],
};

function keysHint() {
    const v = state.view;

    if (v && v.open_mic) {
        return '';
    }

    const keys = [S.voice.space];
    const sc = state.settings && state.settings.ptt_shortcut;

    if (sc) {
        keys.push(sc);
    }

    return S.voice.keys(keys.join(` ${S.or} `));
}

// The panel is built once and updated in place: replacing the talk button while it is held would
// drop the pointer capture and end the transmission.
const panel = {};

function buildPanel() {
    panel.dot = dot();
    panel.title = el('h2');
    panel.state = el('div', { class: 'small muted', role: 'status' });
    panel.listenOnly = el('div', {
        class: 'hint warn',
        text: S.voice.listenOnly,
    });
    panel.big = el('span', { class: 'big' });
    panel.sub = el('span', { class: 'small' });
    panel.ptt = el(
        'button',
        { class: 'ptt', id: 'ptt', type: 'button', 'aria-pressed': 'false' },
        panel.big,
        panel.sub,
    );
    bindPtt(panel.ptt);
    panel.mute = el('button', {
        class: 'btn',
        type: 'button',
        'aria-pressed': 'false',
        onclick: toggleMute,
    });
    panel.keys = el('span', { class: 'small muted' });
    panel.people = el('ul', { class: 'people' });
    panel.nobody = el('div', { class: 'empty', text: S.voice.nobody });
    panel.error = el('div', { class: 'error', role: 'alert' });
    panel.wrap = el(
        'div',
        { class: 'room-panel' },
        el(
            'div',
            { class: 'row between' },
            el('div', { class: 'row' }, panel.dot, panel.title),
            button(S.voice.leave, { kind: 'danger', onclick: leaveRoom }),
        ),
        panel.state,
        panel.listenOnly,
        panel.ptt,
        el('div', { class: 'row between wrap' }, panel.mute, panel.keys),
        el(
            'div',
            {},
            el('div', { class: 'group-title', text: S.voice.inTheRoom }),
            panel.people,
            panel.nobody,
        ),
    );
    panelBox.append(panel.error, panel.wrap);
}

function renderPanel() {
    if (!panelBox) {
        return;
    }

    if (!panel.wrap) {
        buildPanel();
    }

    const v = state.view;
    panel.error.hidden = !state.roomError || inRoom();
    panel.error.textContent = state.roomError || '';
    panel.wrap.hidden = !inRoom();

    if (!inRoom()) {
        return;
    }

    const [tone, text] = PHASE[v.phase] || ['warn', ''];
    const live = v.phase === 'connected' || v.phase === 'reconnecting';
    panel.wrap.classList.toggle('talking', v.talking);
    panel.dot.className =
        'dot ' + (v.talking && v.phase === 'connected' ? 'live' : tone);
    panel.title.textContent = v.room.name;
    panel.state.textContent = v.error ? `${text}: ${v.error}` : text;
    panel.listenOnly.hidden = !v.listen_only;
    panel.ptt.disabled = !live || v.listen_only || v.muted;
    panel.ptt.classList.toggle('talking', v.talking);
    panel.ptt.setAttribute('aria-pressed', String(v.talking));
    panel.big.textContent = v.talking
        ? S.voice.sending
        : v.muted
          ? S.voice.muted
          : S.voice.talk;
    panel.sub.textContent = v.open_mic ? S.voice.openMic : S.voice.holdKey;
    panel.mute.textContent = v.muted ? S.voice.unmute : S.voice.mute;
    panel.mute.setAttribute('aria-pressed', String(v.muted));
    panel.mute.classList.toggle('pressed', v.muted);
    panel.keys.textContent = keysHint();
    panel.nobody.hidden = v.participants.some((p) => !p.is_self);
    panel.people.replaceChildren(
        ...v.participants.map((p) =>
            el(
                'li',
                { class: 'person' + (p.speaking ? ' speaking' : '') },
                dot(p.speaking ? 'live' : p.muted ? '' : 'ok'),
                el('span', {
                    class: 'pname',
                    text: p.is_self ? S.voice.you(p.name) : p.name,
                }),
                el('span', {
                    class: 'tag',
                    text: p.speaking
                        ? S.voice.speaking
                        : p.muted
                          ? S.voice.mutedTag
                          : S.voice.ready,
                }),
            ),
        ),
    );
}

export async function toggleMute() {
    const muted = !(state.view && state.view.muted);
    const r = await call('set_muted', { muted });

    if (r.ok) {
        const v = await call('get_room_view');
        state.view = v.ok ? v.value : r.value;
        renderPanel();
        renderDock();
        say(muted ? S.voice.mutedSaid : S.voice.unmutedSaid);
    } else {
        toast(plainMessage(r.error), 'error');
    }
}

// ---- push to talk: pointer, touch and space ----

let holding = false;

async function pttDown() {
    if (holding) {
        return;
    }

    holding = true;
    const r = await call('set_talking', { talking: true });

    if (!r.ok) {
        holding = false;
        toast(plainMessage(r.error), 'error');
    }
}

async function pttUp() {
    if (!holding) {
        return;
    }

    holding = false;
    await call('set_talking', { talking: false });
}

export function bindPtt(btn) {
    btn.addEventListener('pointerdown', (e) => {
        if (e.button !== 0 || btn.disabled) {
            return;
        }

        btn.setPointerCapture(e.pointerId);
        pttDown();
    });

    for (const ev of ['pointerup', 'pointercancel', 'lostpointercapture']) {
        btn.addEventListener(ev, pttUp);
    }

    // Keyboard: Space or Enter on the focused talk button hold the key.
    btn.addEventListener('keydown', (e) => {
        if ((e.code === 'Space' || e.code === 'Enter') && !e.repeat) {
            e.preventDefault();
            pttDown();
        }
    });
    btn.addEventListener('keyup', (e) => {
        if (e.code === 'Space' || e.code === 'Enter') {
            e.preventDefault();
            pttUp();
        }
    });
}

window.addEventListener('blur', pttUp);

// Space talks on the screens where talking is the point (Jetzt and Sprache). Text fields,
// buttons and selects keep Space for themselves.
function typingTarget(t) {
    return (
        t &&
        (t.tagName === 'INPUT' ||
            t.tagName === 'SELECT' ||
            t.tagName === 'TEXTAREA' ||
            t.tagName === 'BUTTON' ||
            t.isContentEditable)
    );
}

document.addEventListener('keydown', (e) => {
    if (
        e.code !== 'Space' ||
        typingTarget(e.target) ||
        !['jetzt', 'sprache'].includes(state.tab) ||
        !inRoom()
    ) {
        return;
    }

    const v = state.view;

    if (v.listen_only || v.muted || v.phase !== 'connected') {
        return;
    }

    e.preventDefault();

    if (!e.repeat) {
        pttDown();
    }
});
document.addEventListener('keyup', (e) => {
    if (e.code !== 'Space' || typingTarget(e.target)) {
        return;
    }

    pttUp();
});

// ---- the dock: voice stays reachable on every other tab ----

const dockParts = {};

export function renderDock() {
    const dock = document.getElementById('dock');
    const show =
        inRoom() &&
        ['spielen', 'essen', 'mehr'].includes(state.tab) &&
        !document.getElementById('tabs').hidden;
    dock.hidden = !show;

    if (!show) {
        return;
    }

    if (!dockParts.ptt) {
        dockParts.dot = dot();
        dockParts.name = el('span', { class: 'strong' });
        dockParts.hint = chip('');
        dockParts.ptt = el('button', {
            class: 'btn ptt-mini',
            type: 'button',
            'aria-pressed': 'false',
        });
        bindPtt(dockParts.ptt);
        dockParts.mute = el('button', {
            class: 'btn',
            type: 'button',
            'aria-pressed': 'false',
            onclick: toggleMute,
        });
        dock.append(
            dockParts.dot,
            el(
                'button',
                {
                    class: 'dock-room',
                    type: 'button',
                    onclick: () => go('sprache'),
                },
                dockParts.name,
                dockParts.hint,
            ),
            dockParts.ptt,
            dockParts.mute,
        );
    }

    const v = state.view;
    dockParts.dot.className =
        'dot ' + (v.talking ? 'live' : (PHASE[v.phase] || ['warn'])[0]);
    dockParts.name.textContent = v.room.name;
    dockParts.hint.textContent = v.talking ? S.voice.sending : S.voice.dockHint;
    dockParts.ptt.disabled =
        v.listen_only || v.muted || v.phase !== 'connected';
    dockParts.ptt.classList.toggle('talking', v.talking);
    dockParts.ptt.setAttribute('aria-pressed', String(v.talking));
    dockParts.ptt.textContent = v.talking
        ? S.voice.sending
        : S.voice.holdToTalk;
    dockParts.mute.textContent = v.muted ? S.voice.unmute : S.voice.mute;
    dockParts.mute.setAttribute('aria-pressed', String(v.muted));
    dockParts.mute.classList.toggle('pressed', v.muted);
}
