'use strict';

// Speaking overlay: who is talking right now, and our own mute state. Receives the same
// room snapshots as the main window; holds no state of its own.
const { invoke } = window.__TAURI__.core;
const { listen } = window.__TAURI__.event;
const $ = (id) => document.getElementById(id);

function render(v) {
    const inRoom = v && v.room && v.phase !== 'idle';
    $('ov-room').textContent = inRoom ? v.room.name : 'Kein Raum';
    const state = $('ov-state');
    state.className = 'state' + (v && v.talking ? ' on' : '');
    state.textContent = !inRoom
        ? ''
        : v.listen_only
          ? 'nur hören'
          : v.talking
            ? 'du sendest'
            : v.muted
              ? 'stumm'
              : 'bereit';
    $('ov-dot').className =
        'dot ' +
        (v && v.talking
            ? 'live'
            : inRoom && v.phase === 'connected'
              ? 'ok'
              : inRoom
                ? 'warn'
                : '');
    const list = $('ov-list');
    list.replaceChildren();
    const speaking = inRoom ? v.participants.filter((p) => p.speaking) : [];

    for (const p of speaking.slice(0, 5)) {
        const li = document.createElement('li');
        li.className = 'speaking';
        const dot = document.createElement('span');
        dot.className = 'dot';
        dot.setAttribute('aria-hidden', 'true');
        const name = document.createElement('span');
        name.textContent = p.is_self ? p.name + ' (du)' : p.name;
        li.append(dot, name);
        list.append(li);
    }

    const none = $('ov-none');

    if (inRoom) {
        none.textContent = 'Gerade spricht niemand.';
    } else {
        none.textContent = 'Tritt einem Raum bei.';
    }

    none.hidden = speaking.length > 0;
}

listen('room-updated', (e) => render(e.payload));
invoke('get_room_view').then(render, () => render(null));
