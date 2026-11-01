// LANoMAT desktop client, main window. Plain ES modules, no build step. Boots, wires the
// screens and bridges the events of the Rust side into the UI.
import * as connect from './connect.js';
import * as food from './food.js';
import * as more from './more.js';
import {
    buildTabs,
    go,
    initHeader,
    paintQuiet,
    registerScreen,
    resetNav,
    setOnline,
    setWho,
    showScreen,
} from './nav.js';
import * as now from './now.js';
import * as onboarding from './onboarding.js';
import { probeOptional } from './optional.js';
import * as play from './play.js';
import * as settings from './settings.js';
import { S } from './strings.js';
import * as update from './update.js';
import { $, call, emit, listen, on, say, state, toast } from './util.js';
import * as voice from './voice.js';

let setupDone = false;
let authBusy = false;

function init() {
    initHeader();
    $('screen-boot').append(
        ...Array.from({ length: 2 }, () =>
            Object.assign(document.createElement('div'), {
                className: 'skeleton',
            }),
        ),
    );
    $('screen-boot').setAttribute('aria-label', S.loading);
    connect.init($('screen-connect'), {
        connected: () => afterConnect({ fresh: true }),
    });
    onboarding.init($('screen-onboarding'), { done: finishOnboarding });
    now.init($('screen-jetzt'));
    voice.init($('screen-sprache'));
    play.init($('screen-spielen'));
    food.init($('screen-essen'));
    more.init($('screen-mehr'), { logout });
    settings.init();
    registerScreen('jetzt', now);
    registerScreen('sprache', voice);
    registerScreen('spielen', play);
    registerScreen('essen', food);
    registerScreen('mehr', more);

    listen('quiet-changed', (e) => {
        state.quiet = Boolean(e.payload && e.payload.quiet);
        paintQuiet();
        emit('quiet-changed');
    });
    listen('notify-event', (e) => {
        const p = e.payload || {};

        if (p.type === 'unread') {
            state.unread = Number(p.count) || 0;
            emit('unread');
        } else if (p.type === 'auth_expired') {
            authLost({ message: S.errors.authExpired });
        }
    });
    listen('auth-expired', () => authLost({ message: S.errors.authExpired }));
    // The notification plugin has no click callback: when the window comes back to the front,
    // ask where the latest system notification wanted to lead.
    listen('tauri://focus', async (e) => {
        update.focusChanged(e.payload !== false);

        if (e.payload === false || !setupDone) {
            return;
        }

        const r = await call('take_notification_target');

        if (r.ok && r.value) {
            go(r.value.screen, { card: r.value.card, force: true });
        }

        now.refresh();
    });
    on('auth-lost', authLost);
    on('offline', () => setOnline(false));
    on('online', async () => {
        setOnline(true);

        if (!setupDone && state.status && state.status.state !== 'signed_out') {
            await afterConnect({ fresh: false });
        } else if (setupDone) {
            now.refresh();
        }
    });
    on('room-view', refreshWho);
}

function refreshWho() {
    const v = state.view;
    const phase = v && v.room ? v.phase : 'idle';

    if (!state.online) {
        setWho('down');
    } else if (phase === 'reconnecting' || phase === 'failed') {
        setWho('warn');
    } else {
        setWho('ok');
    }
}

function authLost(error) {
    if (authBusy) {
        return;
    }

    authBusy = true;
    setupDone = false;
    update.stop();
    resetNav();
    connect.open({
        title: S.errors.authTitle,
        text: error.message || S.errors.authExpired,
        url: state.status && state.status.server_url,
    });
    say(S.errors.authTitle);
    setTimeout(() => (authBusy = false), 500);
}

async function logout() {
    const r = await call('logout');

    if (!r.ok) {
        toast(r.error.message, 'error');

        return;
    }

    state.view = null;
    state.status = { ...state.status, state: 'signed_out' };
    setupDone = false;
    update.stop();
    resetNav();
    connect.open({ url: state.status.server_url });
    say(S.account.loggedOut);
}

async function afterConnect({ fresh }) {
    // Fresh pairing: the role is only known after a new look at the account.
    const st = await call('get_status');

    if (st.ok) {
        state.status = st.value;
    }

    const [cfg, ev, quiet, unread] = await Promise.all([
        call('get_config'),
        call('current_event'),
        call('get_quiet'),
        call('unread_count'),
    ]);

    if (cfg.ok) {
        state.config = { modules: {}, realtime: false, ...cfg.value };
    }

    state.event = ev.ok ? ev.value : null;
    state.quiet = quiet.ok ? Boolean(quiet.value) : false;
    state.unread = unread.ok ? Number(unread.value) || 0 : 0;
    paintQuiet();
    await probeOptional();
    buildTabs();
    $('brand-name').textContent = S.brand;
    const eventName = $('event-name');
    eventName.hidden = !state.event;
    eventName.textContent = state.event ? state.event.name : '';
    setupDone = Boolean(cfg.ok);
    setWho(state.online ? 'ok' : 'down');
    voice.refreshRooms();
    now.start();
    update.start();

    if (fresh) {
        showScreen('onboarding');
        await onboarding.start();

        return;
    }

    await go('jetzt');
}

async function finishOnboarding() {
    resetNav();
    await go('jetzt');
}

async function boot() {
    const [env, s] = await Promise.all([
        call('get_environment'),
        call('load_settings'),
    ]);
    state.env = env.ok ? env.value : null;
    state.settings = s.ok ? s.value : null;
    const st = await call('get_status');
    const status = st.ok
        ? st.value
        : { state: 'signed_out', server_url: '', message: st.error.message };
    state.status = status;

    if (status.state === 'connected' || status.state === 'unreachable') {
        await afterConnect({ fresh: false });

        // Only a real connection failure is "offline": a 503 is the server being busy.
        if (status.state === 'unreachable' && !state.online) {
            setOnline(false, status.message || undefined);
        }
    } else {
        connect.open({
            title: S.connect.notConnected,
            text: status.message,
            url:
                status.server_url ||
                (state.settings && state.settings.server_url),
        });
    }
}

init();
boot();
