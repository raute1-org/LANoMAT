// Mehr > Einstellungen and Mehr > Gerät und Konto. Settings save on change, like before.
import { categoryToggles } from './notifications.js';
import { S } from './strings.js';
import { versionCard } from './update.js';
import {
    button,
    call,
    el,
    listen,
    plainMessage,
    say,
    state,
    toast,
} from './util.js';

let saveTimer = null;

export function init() {
    listen('settings-changed', (e) => {
        state.settings = e.payload;
    });
    listen('shortcut-status', (e) => {
        if (state.env) {
            state.env.shortcut = e.payload;
        }
    });
}

const KEY_NAMES = {
    ' ': 'Space',
    ArrowUp: 'Up',
    ArrowDown: 'Down',
    ArrowLeft: 'Left',
    ArrowRight: 'Right',
};

export function acceleratorFrom(e) {
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
          : KEY_NAMES[e.key] || e.key;

    if (e.code === 'Space') {
        key = 'Space';
    }

    return [...mods, key].join('+');
}

function check(id, label, hint, checked, onchange) {
    return el(
        'div',
        { class: 'check' },
        el('input', { type: 'checkbox', id, checked, onchange }),
        el(
            'div',
            {},
            el('label', { for: id, text: label }),
            el('div', { class: 'small muted', text: hint }),
        ),
    );
}

export function mountSettings(box, { back, onDevices } = {}) {
    const s = state.settings || {};
    const env = state.env || {};
    const saved = el('div', { class: 'saved', role: 'status' });
    const inSel = el('select', {
        id: 'input-device',
        'aria-label': S.settings.mic,
    });
    const outSel = el('select', {
        id: 'output-device',
        'aria-label': S.settings.speaker,
    });
    const devErr = el('div', { class: 'hint warn', hidden: true });
    const devNote = el('div', {
        class: 'small muted',
        text: S.settings.appliesNext,
    });
    const shortcut = el('input', {
        id: 'shortcut',
        type: 'text',
        readonly: true,
        spellcheck: 'false',
        placeholder: S.settings.pressKey,
        value: s.ptt_shortcut || '',
    });
    const scHint = el('div', { class: 'hint', id: 'shortcut-hint' });
    const dlDir = el('span', { class: 'mono path', id: 'download-dir' });

    const collect = () => ({
        ...state.settings,
        input_device: inSel.value || null,
        output_device: outSel.value || null,
        open_mic: document.getElementById('open-mic').checked,
        headset_mode: document.getElementById('headset-mode').checked,
        close_to_tray: document.getElementById('close-to-tray').checked,
        overlay: document.getElementById('overlay').checked,
        ptt_shortcut: shortcut.value,
    });

    const paintHint = (sc) => {
        if (sc && sc.message) {
            scHint.textContent = sc.message;
            scHint.className = 'hint warn';
        } else if (sc && sc.active) {
            scHint.textContent = S.settings.shortcutActive;
            scHint.className = 'hint';
        } else {
            scHint.textContent = S.settings.shortcutHelp;
            scHint.className = 'hint';
        }
    };

    const save = async (override) => {
        const r = await call('save_settings', {
            settings: override || collect(),
        });

        if (!r.ok) {
            saved.className = 'saved err-text';
            saved.textContent = plainMessage(r.error);

            return false;
        }

        state.settings = r.value.settings;

        if (state.env) {
            state.env.shortcut = r.value.shortcut;
        }

        paintHint(r.value.shortcut);
        saved.className = 'saved';
        saved.textContent = S.saved;
        clearTimeout(saveTimer);
        saveTimer = setTimeout(() => (saved.textContent = ''), 2500);

        return true;
    };

    shortcut.addEventListener('keydown', (e) => {
        if (e.key === 'Tab') {
            return;
        }

        e.preventDefault();

        if (e.key === 'Delete' || e.key === 'Backspace') {
            shortcut.value = '';
            save();

            return;
        }

        if (['Control', 'Alt', 'Shift', 'Meta'].includes(e.key)) {
            return;
        }

        shortcut.value = acceleratorFrom(e);
        save();
    });

    const fill = (sel, names, chosen) => {
        sel.replaceChildren(
            el('option', { value: '', text: S.settings.systemDefault }),
        );

        for (const n of names) {
            sel.append(el('option', { value: n, text: n }));
        }

        if (chosen && !names.includes(chosen)) {
            sel.append(
                el('option', {
                    value: chosen,
                    text: `${chosen} ${S.settings.notFound}`,
                }),
            );
        }

        sel.value = chosen || '';
    };

    const loadDevices = async () => {
        const r = await call('list_devices');

        if (!r.ok) {
            devErr.hidden = false;
            devErr.textContent = `${plainMessage(r.error)} ${S.settings.stayDefault}`;
            fill(inSel, [], s.input_device);
            fill(outSel, [], s.output_device);

            return;
        }

        devErr.hidden = r.value.inputs.length > 0 || r.value.outputs.length > 0;
        devErr.textContent = S.settings.noDevices;
        devNote.textContent = r.value.synthetic
            ? S.settings.synthetic
            : S.settings.appliesNext;
        fill(inSel, r.value.inputs, s.input_device);
        fill(outSel, r.value.outputs, s.output_device);
    };

    const loadDir = async () => {
        const r = await call('get_download_dir');
        dlDir.textContent = r.ok ? r.value : S.settings.dirUnknown;
    };

    const pickDir = async () => {
        const r = await call('pick_directory');

        if (!r.ok) {
            toast(plainMessage(r.error), 'error');

            return;
        }

        if (r.value) {
            await save({ ...collect(), download_dir: r.value });
            loadDir();
        }
    };

    box.append(
        el(
            'div',
            { class: 'page-head' },
            el(
                'div',
                { class: 'row' },
                button(S.back, { kind: 'ghost', onclick: back }),
                el('h1', { text: S.more.settings }),
            ),
        ),
    );
    box.append(
        el(
            'div',
            { class: 'stack' },
            el(
                'section',
                { class: 'card stack-sm' },
                el('h2', { text: S.settings.audio }),
                el(
                    'div',
                    {},
                    el('label', { for: 'input-device', text: S.settings.mic }),
                    inSel,
                ),
                el(
                    'div',
                    {},
                    el('label', {
                        for: 'output-device',
                        text: S.settings.speaker,
                    }),
                    outSel,
                ),
                devErr,
                devNote,
                check(
                    'open-mic',
                    S.settings.openMic,
                    S.settings.openMicHint,
                    Boolean(s.open_mic),
                    () => save(),
                ),
                check(
                    'headset-mode',
                    S.settings.headset,
                    S.settings.headsetHint,
                    Boolean(s.headset_mode),
                    () => save(),
                ),
            ),
            el(
                'section',
                { class: 'card stack-sm' },
                el('h2', { text: S.settings.pttTitle }),
                el(
                    'div',
                    {},
                    el('label', {
                        for: 'shortcut',
                        text: S.settings.globalKey,
                    }),
                    shortcut,
                ),
                el(
                    'div',
                    { class: 'row wrap' },
                    button(S.settings.remove, {
                        onclick: () => {
                            shortcut.value = '';
                            save();
                        },
                    }),
                    button(S.settings.standard, {
                        onclick: () => {
                            shortcut.value = 'Control+Alt+Space';
                            save();
                        },
                    }),
                ),
                scHint,
                env.wayland
                    ? el(
                          'div',
                          { class: 'hint' },
                          el('span', { text: S.settings.wayland1 }),
                          ' ',
                          el('span', {
                              class: 'mono',
                              text: 'lanomat-voice ptt down',
                          }),
                          ' ',
                          el('span', { text: S.and }),
                          ' ',
                          el('span', {
                              class: 'mono',
                              text: 'lanomat-voice ptt up',
                          }),
                          el('span', { text: S.settings.wayland2 }),
                      )
                    : null,
            ),
            el(
                'section',
                { class: 'card stack-sm' },
                el('h2', { text: S.settings.window }),
                check(
                    'close-to-tray',
                    S.settings.closeToTray,
                    S.settings.closeToTrayHint,
                    Boolean(s.close_to_tray),
                    () => save(),
                ),
                check(
                    'overlay',
                    S.settings.overlay,
                    S.settings.overlayHint,
                    Boolean(s.overlay),
                    () => save(),
                ),
            ),
            el(
                'section',
                { class: 'card stack-sm' },
                el('h2', { text: S.settings.downloads }),
                el('div', {
                    class: 'small muted',
                    text: S.settings.downloadsHint,
                }),
                dlDir,
                el(
                    'div',
                    { class: 'row wrap' },
                    button(S.settings.changeFolder, { onclick: pickDir }),
                    button(S.settings.defaultFolder, {
                        onclick: async () => {
                            await save({ ...collect(), download_dir: null });
                            loadDir();
                        },
                    }),
                ),
            ),
            el(
                'section',
                { class: 'stack-sm' },
                el('h2', { text: S.settings.notifications }),
                categoryToggles(),
            ),
            el(
                'div',
                { class: 'card stack-sm small' },
                el(
                    'div',
                    { class: 'row between' },
                    el('span', { class: 'muted', text: S.settings.socket }),
                    el('span', {
                        class: 'mono',
                        text: env.socket_path || S.settings.inactive,
                    }),
                ),
                el(
                    'div',
                    { class: 'row between' },
                    el('span', { class: 'muted', text: S.settings.file }),
                    el('span', {
                        class: 'mono path',
                        text: env.settings_path || '',
                    }),
                ),
                el(
                    'div',
                    { class: 'row between' },
                    el('span', { class: 'muted', text: S.settings.version }),
                    el('span', { class: 'mono', text: env.version || '' }),
                ),
            ),
            saved,
        ),
    );
    paintHint(env.shortcut);
    loadDevices();
    loadDir();

    if (onDevices) {
        onDevices();
    }
}

export function mountAccount(box, { back, logout } = {}) {
    const st = state.status || {};
    const base = (st.server_url || '').replace(/\/+$/, '');
    const msg = el('div', { class: 'small', role: 'status' });
    box.append(
        el(
            'div',
            { class: 'page-head' },
            el(
                'div',
                { class: 'row' },
                button(S.back, { kind: 'ghost', onclick: back }),
                el('h1', { text: S.more.account }),
            ),
        ),
    );
    box.append(
        el(
            'div',
            { class: 'stack' },
            versionCard(),
            el(
                'section',
                { class: 'card stack-sm' },
                el('h2', { text: S.account.thisDevice }),
                el(
                    'div',
                    { class: 'row between' },
                    el('span', { class: 'muted', text: S.account.user }),
                    el('strong', { text: st.user_name || '' }),
                ),
                el(
                    'div',
                    { class: 'row between' },
                    el('span', { class: 'muted', text: S.account.server }),
                    el('span', {
                        class: 'mono path',
                        text: st.server_url || '',
                    }),
                ),
                el('p', { class: 'small muted', text: S.account.revokeHint }),
                el(
                    'div',
                    { class: 'row wrap' },
                    button(S.account.openDevices, {
                        onclick: async () => {
                            const r = await call('open_url', {
                                url: `${base}/talk/connect`,
                            });

                            if (!r.ok) {
                                msg.textContent = plainMessage(r.error);
                                say(msg.textContent);
                            }
                        },
                    }),
                    button(S.account.logout, {
                        kind: 'danger',
                        onclick: logout,
                    }),
                ),
                el('div', { class: 'small muted', text: S.account.logoutHint }),
                msg,
            ),
        ),
    );
}
