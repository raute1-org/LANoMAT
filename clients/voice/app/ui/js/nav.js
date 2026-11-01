// Navigation shell: the five tabs (adapted to the modules that run), badges, the connection
// indicator, the offline banner, the Ruhe toggle and the help sheet of every tab.
import { S } from './strings.js';
import { $, call, clear, el, emit, on, say, state } from './util.js';

export const TAB_IDS = ['jetzt', 'sprache', 'spielen', 'essen', 'mehr'];

// A tab is shown when one of its modules runs. A module key that is missing means "unknown":
// show the feature (see docs/commands.md).
const TAB_MODULES = {
    spielen: ['tournaments', 'game_servers'],
    essen: ['catering'],
};

const screens = new Map();
const badges = Object.fromEntries(TAB_IDS.map((t) => [t, 0]));
let current = null;

export const moduleOn = (key) => state.config.modules[key] !== false;

export function tabVisible(id) {
    const need = TAB_MODULES[id];

    return !need || need.some(moduleOn);
}

export function registerScreen(id, screen) {
    screens.set(id, screen);
}

const ALL = ['boot', 'connect', 'onboarding', ...TAB_IDS];

export function showScreen(name) {
    for (const s of ALL) {
        $('screen-' + s).hidden = s !== name;
    }

    $('screen-boot').setAttribute('aria-busy', String(name === 'boot'));
    const signedIn = TAB_IDS.includes(name);
    $('tabs').hidden = !signedIn;
    $('who').hidden = !signedIn;
    $('quiet').hidden = !signedIn;

    if (!signedIn) {
        $('dock').hidden = true;
        $('offline-banner').hidden = true;
    }

    emit('screen', name);
}

export async function go(tab, opts = {}) {
    let target = TAB_IDS.includes(tab) && tabVisible(tab) ? tab : 'jetzt';

    if (current === target && !opts.force) {
        const s = screens.get(target);

        if (s && s.focus) {
            s.focus(opts);
        }

        return;
    }

    const previous = current;
    current = target;
    state.tab = target;

    if (previous && screens.get(previous) && screens.get(previous).hide) {
        screens.get(previous).hide();
    }

    showScreen(target);
    paintTabs();
    emit('tab', target);
    const s = screens.get(target);

    if (s && s.show) {
        await s.show(opts);
    }
}

export const currentTab = () => current;

export function resetNav() {
    current = null;
}

// ---- tab bar ----

export function buildTabs() {
    const nav = $('tabs');
    clear(nav);
    nav.setAttribute('aria-label', S.nav.label);
    nav.setAttribute('role', 'tablist');

    for (const id of TAB_IDS) {
        if (!tabVisible(id)) {
            continue;
        }

        const badge = el('span', {
            class: 'badge',
            id: `badge-${id}`,
            hidden: true,
        });
        const btn = el(
            'button',
            {
                class: 'tab',
                type: 'button',
                role: 'tab',
                id: `tab-${id}`,
                'data-tab': id,
                onclick: () => go(id),
            },
            el('span', { text: S.tabs[id] }),
            badge,
        );
        nav.append(btn);
    }

    paintTabs();
    paintBadges();
}

function paintTabs() {
    for (const t of document.querySelectorAll('.tab')) {
        const on = t.dataset.tab === current;
        t.setAttribute('aria-selected', String(on));
        t.tabIndex = on ? 0 : -1;

        if (on) {
            t.setAttribute('aria-current', 'page');
        } else {
            t.removeAttribute('aria-current');
        }
    }
}

export function setBadge(tab, n) {
    if (badges[tab] === n) {
        return;
    }

    badges[tab] = n;
    paintBadges();
}

function paintBadges() {
    for (const id of TAB_IDS) {
        const b = $(`badge-${id}`);

        if (!b) {
            continue;
        }

        const n = badges[id];
        b.hidden = n <= 0;
        b.textContent = n > 9 ? '9+' : String(n);
        const tab = $(`tab-${id}`);
        tab.setAttribute(
            'aria-label',
            n > 0 ? `${S.tabs[id]}, ${S.nav.badge(n)}` : S.tabs[id],
        );
    }
}

// Left/right move between the tabs, Alt+1 to Alt+5 jump.
document.addEventListener('keydown', (e) => {
    if (e.altKey && !e.ctrlKey && !e.metaKey && /^[1-5]$/.test(e.key)) {
        if (!$('tabs').hidden) {
            e.preventDefault();
            go(TAB_IDS[Number(e.key) - 1]);
        }

        return;
    }

    if (
        (e.key === 'ArrowLeft' || e.key === 'ArrowRight') &&
        e.target.classList &&
        e.target.classList.contains('tab')
    ) {
        const tabs = [...document.querySelectorAll('.tab')];
        const i = tabs.indexOf(e.target);
        const next =
            tabs[
                (i + (e.key === 'ArrowRight' ? 1 : -1) + tabs.length) %
                    tabs.length
            ];
        e.preventDefault();
        next.focus();
        next.click();
    }
});

// ---- header: who, connection, quiet ----

export function setWho(tone) {
    const s = state.status || {};
    $('who-text').textContent = s.user_name || S.header.connected;
    $('who-dot').className = 'dot ' + tone;
    $('who').title =
        {
            ok: S.header.connected,
            warn: S.header.reconnecting,
            down: S.header.offline,
        }[tone] || '';
}

export function setOnline(online, detail) {
    setWho(online ? 'ok' : 'down');
    const b = $('offline-banner');
    b.hidden = online;

    if (!online) {
        b.textContent = detail || S.header.offlineBanner;
        say(S.header.offline);
    }
}

export function paintQuiet() {
    const q = $('quiet');
    q.setAttribute('aria-pressed', String(state.quiet));
    q.classList.toggle('on', state.quiet);
    q.textContent = state.quiet ? S.quiet.on : S.quiet.off;
    q.title = S.quiet.title;
}

// The node-L mark, built inline so it follows the colour scheme through the tokens (an SVG in
// an <img> does not see the app's dark scheme).
function paintLogo() {
    const ns = 'http://www.w3.org/2000/svg';
    const svg = document.createElementNS(ns, 'svg');
    svg.setAttribute('viewBox', '0 0 24 24');
    const dots = [
        [5.8, 2.7, 'n'],
        [5.8, 8.9, 'n'],
        [5.8, 15.1, 'n'],
        [5.8, 21.3, 'n'],
        [12, 21.3, 'n'],
        [18.2, 21.3, 'a'],
    ];

    for (const [cx, cy, cls] of dots) {
        const c = document.createElementNS(ns, 'circle');
        c.setAttribute('cx', String(cx));
        c.setAttribute('cy', String(cy));
        c.setAttribute('r', '2.5');
        c.setAttribute('class', cls);
        svg.append(c);
    }

    $('logo').replaceChildren(svg);
}

export function initHeader() {
    paintLogo();
    $('brand-name').textContent = S.brand;
    document.title = S.brand;
    $('quiet').addEventListener('click', async () => {
        const r = await call('set_quiet', { quiet: !state.quiet });

        if (r.ok) {
            state.quiet = Boolean(r.value);
            paintQuiet();
            say(state.quiet ? S.quiet.announcedOn : S.quiet.announcedOff);
        }
    });
    paintQuiet();
    on('offline', () => setOnline(false));
    on('online', () => setOnline(true));
}

// ---- help sheet ----

export function openHelp(id) {
    const h = S.help[id];

    if (!h) {
        return;
    }

    const d = $('help');
    clear(d);
    d.append(
        el(
            'div',
            { class: 'stack-sm' },
            el('h2', { id: 'help-title', text: h.title }),
            ...h.lines.map((l) => el('p', { text: l })),
            el(
                'div',
                { class: 'row end' },
                el('button', {
                    class: 'btn primary',
                    type: 'button',
                    text: S.close,
                    onclick: () => d.close(),
                }),
            ),
        ),
    );
    d.showModal();
}

export function helpButton(id) {
    return el('button', {
        class: 'btn ghost help-btn',
        type: 'button',
        text: S.help.button,
        'aria-label': S.help.aria(S.tabs[id] || id),
        onclick: () => openHelp(id),
    });
}

// A page header used by every tab: title, optional sub line and the help button.
export function pageHeader(id, title, extra) {
    return el(
        'div',
        { class: 'page-head' },
        el('h1', { text: title || S.tabs[id] }),
        el('div', { class: 'row' }, extra || null, helpButton(id)),
    );
}
