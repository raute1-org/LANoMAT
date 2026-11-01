'use strict';

// LANoMAT Voice, main window. Plain JS, no build step. Every dynamic string goes in through
// textContent, never innerHTML.
const { invoke } = window.__TAURI__.core;
const { listen } = window.__TAURI__.event;

const $ = (id) => document.getElementById(id);
const el = (tag, props = {}, ...kids) => {
    const node = document.createElement(tag);

    for (const [k, v] of Object.entries(props)) {
        if (k === 'class') {
            node.className = v;
        } else if (k === 'text') {
            node.textContent = v;
        } else if (k.startsWith('on')) {
            node.addEventListener(k.slice(2), v);
        } else {
            node.setAttribute(k, v);
        }
    }

    node.append(...kids);

    return node;
};

const state = {
    screen: 'boot',
    tab: 'rooms',
    status: null,
    view: null,
    rooms: { phase: 'loading', items: [], error: '' },
    settings: null,
    env: null,
    joining: null,
    connectToken: 0,
    verifyUrl: '',
    verifyUrlComplete: '',
};

function say(text) {
    $('live').textContent = '';
    setTimeout(() => ($('live').textContent = text), 30);
}

async function call(cmd, args) {
    try {
        return { ok: true, value: await invoke(cmd, args) };
    } catch (e) {
        const err =
            typeof e === 'object' && e
                ? e
                : { kind: 'local', message: String(e) };

        return { ok: false, error: err };
    }
}

// ---------- screens ----------

function showScreen(name) {
    state.screen = name;

    for (const s of ['boot', 'connect', 'rooms', 'settings']) {
        $('screen-' + s).hidden = s !== name;
    }

    const signedIn = name === 'rooms' || name === 'settings';
    $('tabs').hidden = !signedIn;
    $('who').hidden = !signedIn;

    for (const t of document.querySelectorAll('.tab')) {
        if (t.dataset.tab === name) {
            t.setAttribute('aria-current', 'page');
        } else {
            t.removeAttribute('aria-current');
        }
    }

    $('screen-boot').setAttribute('aria-busy', String(name === 'boot'));
}

function setWho(status, dotClass) {
    $('who-text').textContent = status.user_name || 'Verbunden';
    $('who-dot').className = 'dot ' + dotClass;
}

async function boot() {
    const [env, settings] = await Promise.all([
        call('get_environment'),
        call('load_settings'),
    ]);

    if (env.ok) {
        state.env = env.value;
    }

    if (settings.ok) {
        state.settings = settings.value;
    }

    renderSettings();
    const st = await call('get_status');
    const status = st.ok
        ? st.value
        : { state: 'signed_out', server_url: '', message: st.error.message };
    state.status = status;
    $('server-url').value =
        status.server_url ||
        (state.settings && state.settings.server_url) ||
        '';

    if (status.state === 'connected' || status.state === 'unreachable') {
        setWho(status, status.state === 'connected' ? 'ok' : 'down');
        showScreen('rooms');
        loadRooms();
    } else {
        if (status.message) {
            showConnectError('Nicht verbunden', status.message);
        }

        showScreen('connect');
    }
}

// ---------- connect ----------

function showConnectError(title, text) {
    $('connect-error-title').textContent = title;
    $('connect-error-text').textContent = text;
    $('connect-error').hidden = false;
}

function resetConnect() {
    state.connectToken++;
    $('connect-wait').hidden = true;
    $('connect-form').hidden = false;
    $('connect-submit').disabled = false;
    $('connect-submit').textContent = 'Verbinden';
}

$('connect-form').addEventListener('submit', async (ev) => {
    ev.preventDefault();
    $('connect-error').hidden = true;
    const url = $('server-url').value.trim();

    if (!url) {
        showConnectError(
            'Serveradresse fehlt',
            'Trage die Adresse deines LANoMAT ein, zum Beispiel https://lan.example.org.',
        );
        $('server-url').focus();

        return;
    }

    $('connect-submit').disabled = true;
    $('connect-submit').textContent = 'Verbinde …';
    const r = await call('connect_start', { serverUrl: url });

    if (!r.ok) {
        resetConnect();
        showConnectError(
            r.error.kind === 'network'
                ? 'Server nicht erreichbar'
                : 'Verbinden nicht möglich',
            r.error.message,
        );

        return;
    }

    const s = r.value;
    state.verifyUrl = s.verification_url;
    state.verifyUrlComplete = s.verification_url_complete || s.verification_url;
    $('user-code').textContent = s.user_code;
    $('verify-url').textContent = s.verification_url;
    $('device-name').textContent = s.device_name;
    $('connect-form').hidden = true;
    $('connect-wait').hidden = false;
    say('Code ' + s.user_code + '. Bestätige ihn auf der Webseite.');
    startCountdown(s.expires_in);
    pollLoop(++state.connectToken, s.next_in_ms);
});

let countdownTimer = null;
function startCountdown(seconds) {
    clearInterval(countdownTimer);
    const end = Date.now() + seconds * 1000;
    const tick = () => {
        const left = Math.max(0, Math.round((end - Date.now()) / 1000));
        $('countdown').textContent =
            String(Math.floor(left / 60)).padStart(2, '0') +
            ':' +
            String(left % 60).padStart(2, '0');

        if (left === 0) {
            clearInterval(countdownTimer);
        }
    };
    tick();
    countdownTimer = setInterval(tick, 1000);
}

async function pollLoop(token, waitMs) {
    await new Promise((r) => setTimeout(r, waitMs));

    if (token !== state.connectToken) {
        return;
    }

    const r = await call('connect_poll');

    if (token !== state.connectToken) {
        return;
    }

    if (!r.ok) {
        resetConnect();
        showConnectError('Verbinden fehlgeschlagen', r.error.message);

        return;
    }

    const p = r.value;

    if (p.status === 'pending') {
        return pollLoop(token, p.next_in_ms);
    }

    clearInterval(countdownTimer);

    if (p.status === 'connected') {
        resetConnect();
        state.status = {
            ...state.status,
            state: 'connected',
            user_name: p.user_name,
        };
        setWho(state.status, 'ok');
        showScreen('rooms');
        say('Verbunden.');
        loadRooms();

        return;
    }

    resetConnect();
    const titles = {
        denied: 'Abgelehnt',
        expired: 'Code abgelaufen',
        network: 'Server nicht erreichbar',
        no_flow: 'Keine Verbindung aktiv',
    };
    showConnectError(titles[p.kind] || 'Verbinden fehlgeschlagen', p.message);
    $('connect-submit').textContent = 'Erneut versuchen';
    $('connect-submit').focus();
}

$('connect-cancel').addEventListener('click', async () => {
    resetConnect();
    clearInterval(countdownTimer);
    await call('connect_cancel');
});

async function copy(text, button, done) {
    let ok = false;

    try {
        await navigator.clipboard.writeText(text);
        ok = true;
    } catch {
        const ta = el('textarea', { 'aria-hidden': 'true' });
        ta.value = text;
        document.body.append(ta);
        ta.select();

        try {
            ok = document.execCommand('copy');
        } catch {
            ok = false;
        }

        ta.remove();
    }

    const old = button.textContent;
    button.textContent = ok ? done : 'Kopieren nicht möglich';
    say(ok ? done : 'Kopieren nicht möglich');
    setTimeout(() => (button.textContent = old), 1800);
}
$('copy-code').addEventListener('click', (e) =>
    copy($('user-code').textContent, e.currentTarget, 'Code kopiert'),
);
$('copy-link').addEventListener('click', (e) =>
    copy(state.verifyUrlComplete, e.currentTarget, 'Link kopiert'),
);
$('open-link').addEventListener('click', async () => {
    const r = await call('open_url', { url: state.verifyUrlComplete });

    if (!r.ok) {
        say(r.error.message);
    }
});

// ---------- rooms ----------

$('reload-rooms').addEventListener('click', loadRooms);
$('rooms-retry').addEventListener('click', loadRooms);

async function loadRooms() {
    state.rooms = { phase: 'loading', items: [], error: '' };
    renderRooms();
    const [view, r] = await Promise.all([
        call('get_room_view'),
        call('list_rooms'),
    ]);

    if (view.ok) {
        state.view = view.value;
    }

    if (!r.ok) {
        if (r.error.kind === 'auth') {
            state.status = { ...state.status, state: 'expired' };
            showConnectError('Verbindung abgelaufen', r.error.message);
            showScreen('connect');

            return;
        }

        state.rooms = { phase: 'error', items: [], error: r.error.message };
        setWho(state.status, 'down');
    } else {
        state.rooms = {
            phase: r.value.length ? 'ready' : 'empty',
            items: r.value,
            error: '',
        };
        setWho(state.status, 'ok');
    }

    renderRooms();
    renderRoomPanel();
}

function renderRooms() {
    const { phase, items, error } = state.rooms;
    $('rooms-loading').hidden = phase !== 'loading';
    $('rooms-loading').setAttribute('aria-busy', String(phase === 'loading'));
    $('rooms-error').hidden = phase !== 'error';
    $('rooms-error-text').textContent = error;
    $('rooms-empty').hidden = phase !== 'empty';
    const list = $('rooms-list');
    list.replaceChildren();

    if (phase !== 'ready') {
        return;
    }

    const groups = [
        ['Allgemein', items.filter((r) => r.kind !== 'match')],
        ['Deine Matches', items.filter((r) => r.kind === 'match')],
    ];
    const currentId = state.view && state.view.room ? state.view.room.id : null;

    for (const [title, rooms] of groups) {
        if (!rooms.length) {
            continue;
        }

        const box = el(
            'div',
            { role: 'group', 'aria-label': title },
            el('div', { class: 'group-title', text: title }),
        );
        const stack = el('div', { class: 'stack-sm' });

        for (const room of rooms) {
            const current = room.id === currentId;
            const joining = state.joining === room.id;
            const btn = el('button', {
                class: 'btn',
                type: 'button',
                'aria-label':
                    (current ? 'Verlassen: ' : 'Beitreten: ') + room.name,
                text: joining
                    ? 'Verbinde …'
                    : current
                      ? 'Im Raum'
                      : 'Beitreten',
            });

            if (joining || current) {
                btn.disabled = true;
            }

            btn.addEventListener('click', () => joinRoom(room));
            stack.append(
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
                            : '',
                    ),
                    btn,
                ),
            );
        }

        box.append(stack);
        list.append(box);
    }
}

async function joinRoom(room) {
    state.joining = room.id;
    $('room-error').hidden = true;
    renderRooms();
    const r = await call('join_room', { roomId: room.id });
    state.joining = null;

    if (!r.ok) {
        if (r.error.kind === 'auth') {
            return loadRooms();
        }

        $('room-panel').hidden = false;
        $('room-error').textContent = r.error.message;
        $('room-error').hidden = false;
        renderRooms();
        say(r.error.message);

        return;
    }

    // The room-updated events race with this response: ask for the authoritative snapshot.
    const v = await call('get_room_view');
    state.view = v.ok ? v.value : r.value;
    renderRooms();
    renderRoomPanel();
    say('Raum ' + room.name + ' betreten.');
    $('ptt').focus();
}

$('leave-btn').addEventListener('click', async () => {
    await call('leave_room');
    const v = await call('get_room_view');

    if (v.ok) {
        state.view = v.value;
    }

    renderRooms();
    renderRoomPanel();
    say('Raum verlassen.');
});

const phaseText = {
    connecting: ['warn', 'Verbinde mit dem Raum …'],
    connected: ['ok', 'Verbunden'],
    reconnecting: ['warn', 'Verbindung unterbrochen, verbinde neu …'],
    failed: ['down', 'Verbindung beendet'],
};

function renderRoomPanel() {
    const v = state.view;
    const inRoom = v && v.room && v.phase !== 'idle';
    $('room-panel').hidden = !inRoom && $('room-error').hidden;

    if (!inRoom) {
        return;
    }

    $('room-panel').classList.toggle('talking', v.talking);
    $('room-title').textContent = v.room.name;
    const [cls, text] = phaseText[v.phase] || ['warn', ''];
    $('room-dot').className =
        'dot ' + (v.talking && v.phase === 'connected' ? 'live' : cls);
    $('room-state').textContent = v.error ? text + ': ' + v.error : text;
    $('room-listen-only').hidden = !v.listen_only;
    const live = v.phase === 'connected' || v.phase === 'reconnecting';
    const ptt = $('ptt');
    ptt.disabled = !live || v.listen_only || v.muted;
    ptt.classList.toggle('talking', v.talking);
    ptt.setAttribute('aria-pressed', String(v.talking));
    $('ptt-label').textContent = v.talking
        ? 'Du sendest'
        : v.muted
          ? 'Stumm'
          : 'Sprechen';
    $('ptt-sub').textContent = v.open_mic
        ? 'Mikrofon immer offen'
        : 'Taste gedrückt halten';
    $('mute-btn').textContent = v.muted
        ? 'Stummschaltung aufheben'
        : 'Stumm schalten';
    $('mute-btn').setAttribute('aria-pressed', String(v.muted));
    $('mute-btn').classList.toggle('pressed', v.muted);
    const keys = ['Leertaste'];
    const sc = state.settings && state.settings.ptt_shortcut;

    if (sc) {
        keys.push(sc);
    }

    $('ptt-keys').textContent = v.open_mic
        ? ''
        : 'Taste: ' + keys.join(' oder ');

    const people = $('people');
    people.replaceChildren();
    const others = v.participants.filter((p) => !p.is_self);
    $('people-empty').hidden = others.length > 0;

    for (const p of v.participants) {
        people.append(
            el(
                'li',
                { class: 'person' + (p.speaking ? ' speaking' : '') },
                el('span', {
                    class: 'dot ' + (p.speaking ? 'live' : p.muted ? '' : 'ok'),
                    'aria-hidden': 'true',
                }),
                el('span', {
                    class: 'pname',
                    text: p.is_self ? p.name + ' (du)' : p.name,
                }),
                el('span', {
                    class: 'tag',
                    text: p.speaking ? 'spricht' : p.muted ? 'stumm' : 'bereit',
                }),
            ),
        );
    }
}

listen('room-updated', (e) => {
    state.view = e.payload;
    renderRoomPanel();

    if (state.screen === 'rooms') {
        renderRooms();
    }
});

// ---------- talking: mouse, touch, space ----------

let holding = false;
async function pttDown() {
    if (holding) {
        return;
    }

    holding = true;
    const r = await call('set_talking', { talking: true });

    if (!r.ok) {
        holding = false;
        say(r.error.message);
    }
}
async function pttUp() {
    if (!holding) {
        return;
    }

    holding = false;
    await call('set_talking', { talking: false });
}
const pttBtn = $('ptt');
pttBtn.addEventListener('pointerdown', (e) => {
    if (e.button !== 0 || pttBtn.disabled) {
        return;
    }

    pttBtn.setPointerCapture(e.pointerId);
    pttDown();
});

for (const ev of ['pointerup', 'pointercancel', 'lostpointercapture']) {
    pttBtn.addEventListener(ev, pttUp);
}

window.addEventListener('blur', pttUp);

// Elements that keep Space for themselves: text fields, and every button except the talk button
// (a keyboard user on "Verlassen" must be able to press it).
function typingTarget(t) {
    return (
        t &&
        (t.tagName === 'INPUT' ||
            t.tagName === 'SELECT' ||
            t.tagName === 'TEXTAREA' ||
            (t.tagName === 'BUTTON' && t !== pttBtn))
    );
}
document.addEventListener('keydown', (e) => {
    if (
        e.code !== 'Space' ||
        typingTarget(e.target) ||
        state.screen !== 'rooms'
    ) {
        return;
    }

    if (!state.view || !state.view.room || pttBtn.disabled) {
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

    e.preventDefault();
    pttUp();
});

$('mute-btn').addEventListener('click', async () => {
    const muted = !(state.view && state.view.muted);
    const r = await call('set_muted', { muted });

    if (r.ok) {
        const v = await call('get_room_view');
        state.view = v.ok ? v.value : r.value;
        renderRoomPanel();
        say(muted ? 'Stumm geschaltet.' : 'Stummschaltung aufgehoben.');
    }
});

// ---------- tabs ----------

for (const t of document.querySelectorAll('.tab')) {
    t.addEventListener('click', () => {
        showScreen(t.dataset.tab);

        if (t.dataset.tab === 'settings') {
            loadDevices();
        }
    });
}

// ---------- settings ----------

let saveTimer = null;

function renderSettings() {
    const s = state.settings;

    if (!s) {
        return;
    }

    $('open-mic').checked = s.open_mic;
    $('headset-mode').checked = s.headset_mode;
    $('close-to-tray').checked = s.close_to_tray;
    $('overlay').checked = s.overlay;
    $('shortcut').value = s.ptt_shortcut || '';
    const env = state.env;

    if (env) {
        $('wayland-hint').hidden = !env.wayland;
        $('env-socket').textContent = env.socket_path || 'nicht aktiv';
        $('env-settings').textContent = env.settings_path;
        $('env-version').textContent = env.version;
        renderShortcutHint(env.shortcut);
    }
}

function renderShortcutHint(sc) {
    const hint = $('shortcut-hint');

    if (sc && sc.message) {
        hint.textContent = sc.message;
        hint.classList.add('warn');
    } else if (sc && sc.active) {
        hint.textContent =
            'Aktiv: Taste halten zum Sprechen, loslassen zum Stummschalten.';
        hint.classList.remove('warn');
    } else {
        hint.textContent =
            'Klicke ins Feld und drücke die Kombination. Entf oder Rücktaste entfernt sie.';
        hint.classList.remove('warn');
    }

    if (state.env && state.env.socket_error) {
        $('env-socket').textContent = 'nicht aktiv: ' + state.env.socket_error;
    }
}

function collectSettings() {
    const pick = (id) => $(id).value || null;

    return {
        ...state.settings,
        input_device: pick('input-device'),
        output_device: pick('output-device'),
        open_mic: $('open-mic').checked,
        headset_mode: $('headset-mode').checked,
        close_to_tray: $('close-to-tray').checked,
        overlay: $('overlay').checked,
        ptt_shortcut: $('shortcut').value,
    };
}

async function saveSettings() {
    const r = await call('save_settings', { settings: collectSettings() });

    if (!r.ok) {
        $('saved').style.color = 'var(--destructive)';
        $('saved').textContent = r.error.message;

        return;
    }

    state.settings = r.value.settings;

    if (state.env) {
        state.env.shortcut = r.value.shortcut;
    }

    renderShortcutHint(r.value.shortcut);
    $('saved').style.color = '';
    $('saved').textContent = 'Gespeichert.';
    clearTimeout(saveTimer);
    saveTimer = setTimeout(() => ($('saved').textContent = ''), 2500);
    renderRoomPanel();
}

for (const id of [
    'open-mic',
    'headset-mode',
    'close-to-tray',
    'overlay',
    'input-device',
    'output-device',
]) {
    $(id).addEventListener('change', saveSettings);
}

// Capture a key combination in accelerator syntax, e.g. Control+Alt+Space.
const keyNames = {
    ' ': 'Space',
    ArrowUp: 'Up',
    ArrowDown: 'Down',
    ArrowLeft: 'Left',
    ArrowRight: 'Right',
};
$('shortcut').addEventListener('keydown', (e) => {
    if (e.key === 'Tab') {
        return;
    }

    e.preventDefault();

    if (e.key === 'Delete' || e.key === 'Backspace') {
        $('shortcut').value = '';

        return saveSettings();
    }

    if (['Control', 'Alt', 'Shift', 'Meta'].includes(e.key)) {
        return;
    }

    const mods = [];

    if (e.ctrlKey) {
        mods.push('Control');
    }

    if (e.altKey) {
        mods.push('Alt');
    }

    if (e.shiftKey) {
        mods.push('Shift');
    }

    if (e.metaKey) {
        mods.push('Super');
    }

    let key = e.code.startsWith('Key')
        ? e.code.slice(3)
        : e.code.startsWith('Digit')
          ? e.code.slice(5)
          : keyNames[e.key] || e.key;

    if (e.code === 'Space') {
        key = 'Space';
    }

    $('shortcut').value = [...mods, key].join('+');
    saveSettings();
});
$('shortcut-clear').addEventListener('click', () => {
    $('shortcut').value = '';
    saveSettings();
});
$('shortcut-default').addEventListener('click', () => {
    $('shortcut').value = 'Control+Alt+Space';
    saveSettings();
});

async function loadDevices() {
    const r = await call('list_devices');
    const fill = (sel, names, chosen) => {
        sel.replaceChildren(
            el('option', { value: '', text: 'Systemstandard' }),
        );

        for (const n of names) {
            sel.append(el('option', { value: n, text: n }));
        }

        if (chosen && !names.includes(chosen)) {
            sel.append(
                el('option', {
                    value: chosen,
                    text: chosen + ' (nicht gefunden)',
                }),
            );
        }

        sel.value = chosen || '';
    };
    const s = state.settings || {};

    if (!r.ok) {
        $('devices-error').hidden = false;
        $('devices-error').textContent =
            r.error.message + ' Die Auswahl bleibt auf „Systemstandard“.';
        fill($('input-device'), [], s.input_device);
        fill($('output-device'), [], s.output_device);

        return;
    }

    $('devices-error').hidden =
        r.value.inputs.length > 0 || r.value.outputs.length > 0;
    $('devices-error').textContent =
        'Keine Audiogeräte gefunden. Schließe ein Headset an oder prüfe die Systemeinstellungen.';
    $('devices-note').textContent = r.value.synthetic
        ? 'Testmodus ohne Soundkarte: Es wird ein Testton gesendet.'
        : 'Gilt ab dem nächsten Beitritt.';
    fill($('input-device'), r.value.inputs, s.input_device);
    fill($('output-device'), r.value.outputs, s.output_device);
}

$('open-connect').addEventListener('click', async () => {
    const base = (state.status && state.status.server_url) || '';
    const r = await call('open_url', {
        url: base.replace(/\/+$/, '') + '/talk/connect',
    });

    if (!r.ok) {
        say(r.error.message);
    }
});

$('logout').addEventListener('click', async () => {
    const r = await call('logout');

    if (!r.ok) {
        return say(r.error.message);
    }

    state.view = null;
    state.status = { ...state.status, state: 'signed_out' };
    $('room-panel').hidden = true;
    resetConnect();
    showScreen('connect');
    say('Abgemeldet.');
});

listen('shortcut-status', (e) => {
    if (state.env) {
        state.env.shortcut = e.payload;
    }

    renderShortcutHint(e.payload);
});
listen('settings-changed', (e) => {
    state.settings = e.payload;
    renderSettings();
});

boot();
