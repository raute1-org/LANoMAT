// Keyboard and gamepad navigation for the arcade cabinet.
//
// A terminal has a controller on the shelf and no mouse on the desk: the D-pad
// or the left stick moves the focus through the visible buttons and cards of the
// current screen, A (or Start) activates the focused control, B goes back. The
// keyboard keeps working unchanged — Tab/Enter native, the arrow keys move the
// same focus — so a keyboard-only cabinet is never worse off.
//
// The Gamepad API is polled (no dependency): WebKitGTK does not reliably fire
// `gamepadconnected`, and a controller plugged in after boot has to be picked
// up too. Every connection change is mirrored into `state.gamepad`, painted in
// the footer and reported to the orga heartbeat by `main.js`.
import { $, focusNode, setText } from './util.js';
import { S } from './strings.js';
import { state } from './state.js';

const SELECTOR =
    'button, a[href], input, select, textarea, [tabindex]:not([tabindex="-1"])';
const DEADZONE = 0.55;
const FIRST_REPEAT_MS = 380;
const NEXT_REPEAT_MS = 160;
const ARROWS = {
    ArrowUp: 'up',
    ArrowDown: 'down',
    ArrowLeft: 'left',
    ArrowRight: 'right',
};

let back = () => false;
let onPad = () => {};
let connected = null;
let held = null;
let buttons = { a: false, b: false };

/** Wire the keyboard/gamepad navigation. `back` returns whether it consumed the step. */
export function init(options = {}) {
    back = options.back || back;
    onPad = options.gamepad || onPad;

    window.addEventListener('keydown', onKey);
    window.addEventListener('gamepadconnected', () => paint(readPads().length > 0));
    window.addEventListener('gamepaddisconnected', () => paint(readPads().length > 0));

    paint(false);
    loop();
}

function onKey(event) {
    if (event.key === 'Escape') {
        event.preventDefault();
        back();

        return;
    }

    if (!state.arcade || event.defaultPrevented) {
        return;
    }

    const dir = ARROWS[event.key];

    if (!dir || isTyping(event.target)) {
        return;
    }

    event.preventDefault();
    move(dir);
}

function isTyping(node) {
    return (
        node instanceof HTMLElement &&
        (node.tagName === 'INPUT' ||
            node.tagName === 'TEXTAREA' ||
            node.tagName === 'SELECT')
    );
}

// ---- focus movement ----

// The header stays reachable (abmelden/next device) and the screen supplies the
// rest. Hidden, disabled and aria-disabled controls are not stops: the install
// cancel button keeps its focus through `aria-disabled`, it must not swallow it.
function stops() {
    const screen = document.getElementById(`screen-${state.stage}`);
    const roots = [document.querySelector('.bar'), screen].filter(Boolean);
    const seen = new Set();
    const nodes = [];

    for (const root of roots) {
        for (const node of root.querySelectorAll(SELECTOR)) {
            if (seen.has(node) || node.disabled) {
                continue;
            }

            if (node.getAttribute('aria-disabled') === 'true') {
                continue;
            }

            if (node.closest('[hidden]') || !node.getClientRects().length) {
                continue;
            }

            seen.add(node);
            nodes.push(node);
        }
    }

    return nodes;
}

function move(dir) {
    const nodes = stops();

    if (nodes.length === 0) {
        return;
    }

    const current = nodes.indexOf(document.activeElement);
    let next;

    if (current < 0) {
        next = 0;
    } else if (dir === 'down' || dir === 'right') {
        next = (current + 1) % nodes.length;
    } else {
        next = (current - 1 + nodes.length) % nodes.length;
    }

    focusNode(nodes[next]);
}

function activate() {
    const node = document.activeElement;

    if (!node || node === document.body || isTyping(node)) {
        move('down');

        return;
    }

    if (stops().includes(node) && typeof node.click === 'function') {
        node.click();
    }
}

// ---- gamepad polling ----

function loop() {
    window.requestAnimationFrame(loop);

    const pads = readPads();

    paint(pads.length > 0);

    const pad = pads[0];

    if (!pad) {
        held = null;
        buttons = { a: false, b: false };

        return;
    }

    step(pad, performance.now());
}

function step(pad, now) {
    const dir = direction(pad);

    if (!dir) {
        held = null;
    } else if (!held || held.dir !== dir) {
        held = { dir, next: now + FIRST_REPEAT_MS };
        move(dir);
    } else if (now >= held.next) {
        held.next = now + NEXT_REPEAT_MS;
        move(dir);
    }

    const a = pressed(pad, 0) || pressed(pad, 9);

    if (a && !buttons.a) {
        activate();
    }

    buttons.a = a;

    const b = pressed(pad, 1) || pressed(pad, 8);

    if (b && !buttons.b) {
        back();
    }

    buttons.b = b;
}

function readPads() {
    if (typeof navigator?.getGamepads !== 'function') {
        return [];
    }

    try {
        return [...navigator.getGamepads()].filter(Boolean);
    } catch {
        return [];
    }
}

function pressed(pad, index) {
    const button = pad.buttons?.[index];

    if (button === undefined) {
        return false;
    }

    return typeof button === 'object'
        ? Boolean(button.pressed || button.value > 0.5)
        : Boolean(button);
}

// D-pad first (12 up, 13 down, 14 left, 15 right), then the left stick axes.
function direction(pad) {
    if (pressed(pad, 12)) {
        return 'up';
    }

    if (pressed(pad, 13)) {
        return 'down';
    }

    if (pressed(pad, 14)) {
        return 'left';
    }

    if (pressed(pad, 15)) {
        return 'right';
    }

    const [x = 0, y = 0] = pad.axes || [];

    if (Math.abs(x) < DEADZONE && Math.abs(y) < DEADZONE) {
        return null;
    }

    if (Math.abs(y) >= Math.abs(x)) {
        return y < 0 ? 'up' : 'down';
    }

    return x < 0 ? 'left' : 'right';
}

// ---- the footer indicator ----

function paint(value) {
    if (connected === value) {
        return;
    }

    connected = value;
    state.gamepad = value;

    const dot = $('gamepad-dot');

    if (dot) {
        dot.classList.toggle('ok', value);
        dot.classList.toggle('down', !value);
    }

    const line = $('gamepad');

    setText('gamepad-label', value ? S.gamepad.connected : S.gamepad.disconnected);

    if (line) {
        line.title = S.gamepad.hint;
    }

    onPad(value);
}
