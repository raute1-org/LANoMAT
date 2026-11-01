// First-run guide, a 20-second check after the first connect: headset, a moving input meter,
// a test tone, push-to-talk key or open mic. Then straight to Jetzt.
import { acceleratorFrom } from './settings.js';
import { S } from './strings.js';
import {
    button,
    call,
    add,
    clear,
    el,
    listen,
    plainMessage,
    say,
    state,
} from './util.js';

const STEPS = ['headset', 'level', 'tone', 'key'];
let box = null;
let finish = () => {};
let step = 0;
let level = 0;
let peak = 0;
let meterFill = null;
let meterText = null;
let checking = false;

export function init(section, { done }) {
    box = section;
    finish = done;
    listen('audio-level', (e) => {
        level = Number(e.payload && e.payload.level) || 0;
        peak = Math.max(peak, level);
        paintMeter();
    });
}

export async function start() {
    step = 0;
    peak = 0;
    level = 0;
    await draw();
}

async function stopCheck() {
    if (checking) {
        checking = false;
        await call('audio_check_stop');
    }
}

function paintMeter() {
    if (!meterFill) {
        return;
    }

    meterFill.style.width = `${Math.round(Math.min(1, level) * 100)}%`;

    if (meterText) {
        meterText.textContent =
            peak > 0.04 ? S.onboarding.heard : S.onboarding.silence;
    }

    meterFill.parentElement.setAttribute(
        'aria-valuenow',
        String(Math.round(level * 100)),
    );
}

async function go(n) {
    await stopCheck();
    step = Math.max(0, Math.min(STEPS.length, n));
    await draw();
}

async function skip() {
    await stopCheck();
    finish();
}

async function draw() {
    clear(box);
    meterFill = null;
    meterText = null;
    const id = STEPS[step];
    const t = S.onboarding;
    const header = el(
        'div',
        { class: 'stack-sm' },
        el('div', {
            class: 'small muted',
            text: t.stepOf(step + 1, STEPS.length),
        }),
        el('h1', { text: t.title[id] }),
        el('p', { class: 'muted', text: t.intro[id] }),
    );
    const dots = el(
        'ol',
        { class: 'steps', 'aria-label': t.progress },
        ...STEPS.map((s, i) =>
            el('li', {
                class: i === step ? 'current' : i < step ? 'done' : '',
                'aria-current': i === step ? 'step' : null,
                text: t.title[s],
            }),
        ),
    );
    const card = el('div', { class: 'card stack-sm' });
    const nav = el(
        'div',
        { class: 'row between wrap' },
        button(t.skip, { kind: 'ghost', onclick: skip }),
        el(
            'div',
            { class: 'row' },
            step > 0 ? button(t.back, { onclick: () => go(step - 1) }) : null,
            el('button', {
                class: 'btn primary',
                id: 'onb-next',
                type: 'button',
                text: step === STEPS.length - 1 ? t.finish : t.next,
                onclick: () =>
                    step === STEPS.length - 1 ? skip() : go(step + 1),
            }),
        ),
    );
    box.append(header, dots, card, nav);

    if (id === 'headset') {
        await headsetStep(card);
    } else if (id === 'level') {
        await levelStep(card);
    } else if (id === 'tone') {
        toneStep(card);
    } else {
        keyStep(card);
    }

    document.getElementById('onb-next').focus({ preventScroll: true });
}

async function save(patch) {
    const r = await call('save_settings', {
        settings: { ...state.settings, ...patch },
    });

    if (r.ok) {
        state.settings = r.value.settings;
    }

    return r;
}

async function headsetStep(card) {
    const r = await call('list_devices');
    const inSel = el('select', {
        id: 'onb-input',
        'aria-label': S.settings.mic,
    });
    const outSel = el('select', {
        id: 'onb-output',
        'aria-label': S.settings.speaker,
    });
    const fill = (sel, names, chosen) => {
        sel.replaceChildren(
            el('option', { value: '', text: S.settings.systemDefault }),
            ...names.map((n) => el('option', { value: n, text: n })),
        );
        sel.value = chosen || '';
    };
    const s = state.settings || {};
    fill(inSel, r.ok ? r.value.inputs : [], s.input_device);
    fill(outSel, r.ok ? r.value.outputs : [], s.output_device);
    inSel.addEventListener('change', () =>
        save({ input_device: inSel.value || null }),
    );
    outSel.addEventListener('change', () =>
        save({ output_device: outSel.value || null }),
    );
    add(
        card,
        el(
            'div',
            {},
            el('label', { for: 'onb-input', text: S.settings.mic }),
            inSel,
        ),
        el(
            'div',
            {},
            el('label', { for: 'onb-output', text: S.settings.speaker }),
            outSel,
        ),
        !r.ok
            ? el('div', {
                  class: 'hint warn',
                  text: `${plainMessage(r.error)} ${S.settings.stayDefault}`,
              })
            : null,
        r.ok && !r.value.inputs.length
            ? el('div', { class: 'hint warn', text: S.settings.noDevices })
            : null,
        r.ok && r.value.synthetic
            ? el('div', { class: 'small muted', text: S.settings.synthetic })
            : null,
    );
}

async function levelStep(card) {
    const s = state.settings || {};
    peak = 0;
    level = 0;
    meterFill = el('span', { class: 'bar-fill meter-fill' });
    meterText = el('div', {
        class: 'strong',
        role: 'status',
        text: S.onboarding.silence,
    });
    const track = el(
        'div',
        {
            class: 'bar-track meter',
            role: 'progressbar',
            'aria-valuemin': '0',
            'aria-valuemax': '100',
            'aria-valuenow': '0',
            'aria-label': S.onboarding.meter,
        },
        meterFill,
    );
    add(
        card,
        track,
        meterText,
        el('div', { class: 'small muted', text: S.onboarding.levelHint }),
    );
    const r = await call('audio_check_start', {
        inputDevice: s.input_device || null,
    });

    if (!r.ok) {
        add(
            card,
            el('div', {
                class: 'error',
                role: 'alert',
                text: plainMessage(r.error),
            }),
        );

        return;
    }

    checking = true;
    say(S.onboarding.speakNow);
}

function toneStep(card) {
    const msg = el('div', { class: 'small', role: 'status' });
    const s = state.settings || {};
    const play = button(S.onboarding.playTone, {
        kind: 'primary-soft',
        onclick: async (ev) => {
            const btn = ev.currentTarget;
            btn.disabled = true;
            msg.textContent = S.onboarding.playing;
            const r = await call('play_test_tone', {
                outputDevice: s.output_device || null,
            });
            btn.disabled = false;
            msg.textContent = r.ok
                ? S.onboarding.heardQuestion
                : plainMessage(r.error);
        },
    });
    add(
        card,
        play,
        msg,
        el('div', { class: 'small muted', text: S.onboarding.toneHint }),
    );
}

function keyStep(card) {
    const s = state.settings || {};
    const field = el('input', {
        id: 'onb-key',
        type: 'text',
        readonly: true,
        spellcheck: 'false',
        value: s.ptt_shortcut || '',
        placeholder: S.settings.pressKey,
    });
    const msg = el('div', { class: 'small', role: 'status' });
    const open = el('input', {
        type: 'radio',
        name: 'mode',
        id: 'mode-open',
        checked: Boolean(s.open_mic),
    });
    const ptt = el('input', {
        type: 'radio',
        name: 'mode',
        id: 'mode-ptt',
        checked: !s.open_mic,
    });
    const apply = async () => {
        const r = await save({
            open_mic: open.checked,
            ptt_shortcut: field.value,
        });
        msg.textContent = r.ok ? S.saved : plainMessage(r.error);
        field.disabled = open.checked;
    };
    open.addEventListener('change', apply);
    ptt.addEventListener('change', apply);
    field.addEventListener('keydown', (e) => {
        if (e.key === 'Tab') {
            return;
        }

        e.preventDefault();

        if (['Control', 'Alt', 'Shift', 'Meta'].includes(e.key)) {
            return;
        }

        field.value =
            e.key === 'Delete' || e.key === 'Backspace'
                ? ''
                : acceleratorFrom(e);
        apply();
    });
    field.disabled = Boolean(s.open_mic);
    add(
        card,
        el(
            'div',
            { class: 'check' },
            ptt,
            el(
                'div',
                {},
                el('label', { for: 'mode-ptt', text: S.onboarding.pttMode }),
                el('div', {
                    class: 'small muted',
                    text: S.onboarding.pttModeHint,
                }),
            ),
        ),
        el(
            'div',
            { class: 'indent' },
            el('label', { for: 'onb-key', text: S.settings.globalKey }),
            field,
        ),
        el(
            'div',
            { class: 'check' },
            open,
            el(
                'div',
                {},
                el('label', { for: 'mode-open', text: S.onboarding.openMode }),
                el('div', {
                    class: 'small muted',
                    text: S.onboarding.openModeHint,
                }),
            ),
        ),
        msg,
    );
}
