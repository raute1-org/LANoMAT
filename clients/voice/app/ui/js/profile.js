// Mehr > Profil: the profile as the server returns it (only the fields it lets you change are
// editable), the privacy switch for "Meinen Standort teilen", and the linked accounts with the
// browser round trip of "Verknüpfen" (the link is opened in the system browser, the screen waits
// calmly and asks the server until the account shows up).
import { avatar, inlineConfirm } from './shared.js';
import { S } from './strings.js';
import {
    button,
    call,
    chip,
    clear,
    dot,
    el,
    errorState,
    plainMessage,
    say,
    skeleton,
    toast,
} from './util.js';

const NULLABLE = ['bio', 'steam_url', 'stream_url', 'profile_color'];
const ORDER = [
    'name',
    'email',
    'bio',
    'steam_url',
    'stream_url',
    'profile_color',
];
const POLL_MS = 3000;
const COLOR = /^#[0-9a-fA-F]{6}$/;

let live = false;
let pollTimer = null;

export function unmount() {
    live = false;
    clearTimeout(pollTimer);
}

export function mount(box, { back }) {
    live = true;
    const body = el('div', { class: 'stack' });
    box.append(
        el(
            'div',
            { class: 'page-head' },
            el(
                'div',
                { class: 'row' },
                button(S.back, { kind: 'ghost', onclick: back }),
                el('h1', { text: S.profile.title }),
            ),
        ),
        body,
    );

    let profile = null;
    let loadError = null;
    // Built once: a repaint of the form must not drop a link that is waiting for the browser.
    const connections = connectionsCard();

    const load = async () => {
        const r = await call('get_profile');

        if (!live) {
            return;
        }

        if (r.ok) {
            profile = r.value;
            loadError = null;
        } else {
            loadError = r.error;
        }

        paint();
    };

    function paint() {
        clear(body);

        if (loadError && !profile) {
            body.append(
                errorState(
                    loadError,
                    () => {
                        loadError = null;
                        paint();
                        load();
                    },
                    S.profile.failed,
                ),
            );

            return;
        }

        if (!profile) {
            body.append(skeleton(3));

            return;
        }

        body.append(
            header(profile),
            formCard(profile, (next) => {
                profile = next;
                paint();
            }),
        );

        if (typeof profile.share_location === 'boolean') {
            body.append(privacyCard(profile));
        }

        body.append(connections);
    }

    paint();
    load();
}

function header(p) {
    return el(
        'div',
        { class: 'row' },
        avatar(p.name, { me: true, src: p.avatar_url }),
        el(
            'div',
            { class: 'grow' },
            el('div', { class: 'name', text: p.name }),
            el('div', { class: 'small muted', text: S.profile.avatarNote }),
        ),
    );
}

// ---- the form ----

function fieldValue(p, key) {
    const v = p[key];

    return v === null || v === undefined ? '' : String(v);
}

function formCard(p, onSaved) {
    const editable = new Set(p.editable || []);
    const form = el('form', { class: 'card stack', novalidate: '' });
    const summary = el('div', {
        class: 'inline-error',
        role: 'alert',
        hidden: true,
    });
    const inputs = {};
    const errors = {};
    form.append(el('h2', { text: S.profile.formTitle }), summary);

    for (const key of ORDER) {
        const id = `pf-${key}`;
        const mine = editable.has(key);
        const hint = S.profile.hints[key];
        const err = el('div', {
            class: 'field-error',
            id: `${id}-err`,
            role: 'alert',
            hidden: true,
        });
        errors[key] = err;
        const label = el('label', { for: id, text: S.profile.fields[key] });
        let input;

        if (key === 'bio') {
            input = el('textarea', {
                id,
                rows: '4',
                value: fieldValue(p, key),
                disabled: !mine,
            });
        } else {
            input = el('input', {
                id,
                type: key === 'email' ? 'email' : 'text',
                value: fieldValue(p, key),
                disabled: !mine,
                autocomplete: 'off',
                spellcheck: 'false',
            });
        }

        input.setAttribute(
            'aria-describedby',
            [hint ? `${id}-hint` : null, `${id}-err`].filter(Boolean).join(' '),
        );
        inputs[key] = input;
        const extra = [];

        if (key === 'email' && fieldValue(p, key)) {
            extra.push(
                chip(
                    p.email_verified
                        ? S.profile.emailDone
                        : S.profile.emailOpen,
                    p.email_verified ? 'ok' : 'warn',
                ),
            );
        }

        if (key === 'bio') {
            const counter = el('span', {
                class: 'small muted counter',
                text: S.profile.counter(input.value.length),
            });
            input.addEventListener('input', () => {
                counter.textContent = S.profile.counter(input.value.length);
            });
            extra.push(counter);
        }

        if (key === 'profile_color') {
            const swatch = el('span', {
                class: 'swatch',
                'aria-hidden': 'true',
            });
            const paintSwatch = () => {
                swatch.style.background = COLOR.test(input.value.trim())
                    ? input.value.trim()
                    : 'transparent';
            };
            input.addEventListener('input', paintSwatch);
            paintSwatch();
            extra.push(swatch);
        }

        form.append(
            el(
                'div',
                { class: 'field' },
                el('div', { class: 'row between wrap' }, label, ...extra),
                input,
                hint
                    ? el('div', {
                          class: 'small muted',
                          id: `${id}-hint`,
                          text: hint,
                      })
                    : null,
                !mine
                    ? el('div', {
                          class: 'small muted',
                          text: S.profile.readOnly,
                      })
                    : null,
                err,
            ),
        );
    }

    const save = el('button', {
        class: 'btn primary',
        type: 'submit',
        text: S.profile.save,
    });
    const discard = button(S.profile.discard, {
        kind: 'ghost',
        onclick: () => onSaved(p),
    });
    form.append(el('div', { class: 'row wrap' }, save, discard));

    // Only what changed is sent; an emptied optional field is sent as null (clears it).
    const changes = () => {
        const out = {};

        for (const key of ORDER) {
            if (!editable.has(key)) {
                continue;
            }

            const v =
                key === 'bio' ? inputs[key].value : inputs[key].value.trim();

            if (v === fieldValue(p, key)) {
                continue;
            }

            out[key] = v === '' && NULLABLE.includes(key) ? null : v;
        }

        return out;
    };

    const showErrors = (error) => {
        const fields = error.fields || {};
        let first = null;

        for (const key of ORDER) {
            const list = fields[key];
            const has = Array.isArray(list) && list.length > 0;
            errors[key].hidden = !has;
            errors[key].textContent = has ? list.join(' ') : '';
            inputs[key].setAttribute('aria-invalid', String(has));

            if (has && !first) {
                first = inputs[key];
            }
        }

        summary.hidden = false;
        summary.textContent = first
            ? S.profile.fixFields
            : plainMessage(error) || S.profile.saveFailed;

        if (first) {
            first.focus();
        }
    };

    form.addEventListener('submit', async (e) => {
        e.preventDefault();
        const patch = changes();
        summary.hidden = true;

        for (const key of ORDER) {
            errors[key].hidden = true;
            inputs[key].removeAttribute('aria-invalid');
        }

        if (!Object.keys(patch).length) {
            toast(S.profile.unchanged);

            return;
        }

        save.disabled = true;
        save.textContent = S.profile.saving;
        const r = await call('update_profile', { changes: patch });

        if (!live) {
            return;
        }

        if (r.ok) {
            toast(S.profile.saved);
            say(S.profile.saved);
            onSaved(r.value);

            return;
        }

        save.disabled = false;
        save.textContent = S.profile.save;
        showErrors(r.error);
    });

    return form;
}

// ---- privacy ----

function privacyCard(p) {
    const status = el('div', {
        class: 'small',
        id: 'pf-share-status',
        role: 'status',
    });
    const editable = (p.editable || []).includes('share_location');
    const box = el('input', {
        type: 'checkbox',
        id: 'pf-share',
        role: 'switch',
        checked: p.share_location,
        disabled: !editable,
        'aria-describedby': 'pf-share-text pf-share-status',
    });
    const paintStatus = (on) => {
        status.textContent = on ? S.profile.shareOn : S.profile.shareOff;
    };
    paintStatus(p.share_location);
    box.addEventListener('change', async () => {
        const want = box.checked;
        box.disabled = true;
        const r = await call('update_profile', {
            changes: { share_location: want },
        });

        if (!live) {
            return;
        }

        box.disabled = !editable;

        if (r.ok) {
            const now =
                typeof r.value.share_location === 'boolean'
                    ? r.value.share_location
                    : want;
            box.checked = now;
            paintStatus(now);
            say(now ? S.profile.shareOn : S.profile.shareOff);
            toast(S.profile.shareSaved);
        } else {
            box.checked = !want;
            paintStatus(!want);
            toast(plainMessage(r.error), 'error');
        }
    });

    return el(
        'section',
        { class: 'card stack-sm', 'aria-label': S.profile.privacy },
        el('h2', { text: S.profile.privacy }),
        el(
            'div',
            { class: 'check' },
            box,
            el(
                'div',
                {},
                el('label', { for: 'pf-share', text: S.profile.share }),
                el('div', {
                    class: 'small muted',
                    id: 'pf-share-text',
                    text: S.profile.shareText,
                }),
            ),
        ),
        status,
    );
}

// ---- linked accounts ----

function connectionsCard() {
    const card = el('section', {
        class: 'card stack-sm',
        'aria-label': S.profile.connections,
    });
    let list = null;
    let listError = null;
    let confirming = null; // provider asked "really?"
    let busy = null;
    let waiting = null; // {provider, deadline}
    let note = null; // {provider, text}

    const fetchList = async () => {
        const r = await call('list_connections');

        if (!live) {
            return null;
        }

        if (r.ok) {
            list = r.value || [];
            listError = null;
        } else {
            listError = r.error;
        }

        return r;
    };

    const stopWaiting = () => {
        waiting = null;
        clearTimeout(pollTimer);
    };

    // Asks the server every few seconds until the provider shows as connected or the link has
    // expired (its 10 minutes plus a minute for the round trip at the provider).
    const poll = () => {
        clearTimeout(pollTimer);
        pollTimer = setTimeout(async () => {
            if (!live || !waiting) {
                return;
            }

            const r = await fetchList();

            if (!live || !waiting) {
                return;
            }

            const c = (list || []).find((x) => x.provider === waiting.provider);

            if (r && r.ok && c && c.status === 'connected') {
                const label = c.label || c.provider;
                stopWaiting();
                toast(S.profile.linked(label));
                say(S.profile.linked(label));
                paint();

                return;
            }

            if (Date.now() > waiting.deadline) {
                note = { provider: waiting.provider, text: S.profile.timedOut };
                stopWaiting();
                paint();

                return;
            }

            poll();
        }, POLL_MS);
    };

    const startLink = async (c) => {
        busy = c.provider;
        note = null;
        paint();
        const r = await call('connection_link_url', { provider: c.provider });

        if (!live) {
            return;
        }

        busy = null;

        if (!r.ok) {
            note = { provider: c.provider, text: plainMessage(r.error) };
            paint();

            return;
        }

        // The link is single use and secret: it goes straight to the browser, never on screen.
        const opened = await call('open_link_url', { url: r.value.url });

        if (!live) {
            return;
        }

        if (!opened.ok) {
            note = { provider: c.provider, text: plainMessage(opened.error) };
            paint();

            return;
        }

        const expires = Date.parse(r.value.expires_at || '');
        waiting = {
            provider: c.provider,
            deadline:
                (Number.isNaN(expires) ? Date.now() + 600000 : expires) + 60000,
        };
        paint();
        poll();
    };

    const unlink = async (c) => {
        busy = c.provider;
        confirming = null;
        paint();
        const r = await call('unlink_connection', { provider: c.provider });

        if (!live) {
            return;
        }

        busy = null;

        if (r.ok) {
            const label = c.label || c.provider;
            toast(S.profile.unlinked(label));
            say(S.profile.unlinked(label));
            await fetchList();
        } else {
            note = { provider: c.provider, text: plainMessage(r.error) };
        }

        paint();
    };

    const row = (c) => {
        const label = c.label || c.provider;

        if (confirming === c.provider) {
            return el(
                'li',
                { class: 'pr' },
                inlineConfirm({
                    title: S.profile.confirmUnlinkTitle(label),
                    text: S.profile.confirmUnlinkText,
                    yes: S.profile.unlink,
                    no: S.profile.keep,
                    danger: true,
                    onYes: () => unlink(c),
                    onNo: () => {
                        confirming = null;
                        paint();
                    },
                }),
            );
        }

        const isWaiting = waiting && waiting.provider === c.provider;
        const tone =
            c.status === 'connected'
                ? 'ok'
                : c.status === 'needs_reauth'
                  ? 'warn'
                  : '';
        const statusText = S.profile.status[c.status] || c.status || '';
        const sub = [];

        if (c.read_only) {
            sub.push(S.profile.loginAccount);
        }

        if (!c.available && !c.read_only) {
            sub.push(S.profile.unavailable);
        }

        const actions = [];

        if (!c.read_only && !isWaiting) {
            if (c.can_link) {
                actions.push(
                    el('button', {
                        class: `btn small-btn ${c.status === 'needs_reauth' ? 'primary' : ''}`.trim(),
                        type: 'button',
                        text:
                            c.status === 'needs_reauth'
                                ? S.profile.relink
                                : S.profile.link,
                        'aria-label': S.profile.linkAria(label),
                        disabled: busy !== null || waiting !== null,
                        onclick: () => startLink(c),
                    }),
                );
            }

            if (c.can_unlink) {
                actions.push(
                    el('button', {
                        class: 'btn small-btn danger',
                        type: 'button',
                        text: S.profile.unlink,
                        'aria-label': S.profile.unlinkAria(label),
                        disabled: busy !== null || waiting !== null,
                        onclick: () => {
                            confirming = c.provider;
                            note = null;
                            paint();
                        },
                    }),
                );
            }
        }

        return el(
            'li',
            { class: 'pr stacked' },
            el(
                'div',
                { class: 'row wrap fill' },
                el(
                    'div',
                    { class: 'grow' },
                    el(
                        'div',
                        { class: 'row' },
                        dot(tone),
                        el('span', { class: 'name', text: label }),
                        chip(statusText, tone),
                    ),
                    c.external_name && c.status !== 'not_connected'
                        ? el('div', {
                              class: 'small muted',
                              text: S.profile.as(c.external_name),
                          })
                        : null,
                    sub.length
                        ? el('div', {
                              class: 'small muted',
                              text: sub.join(', '),
                          })
                        : null,
                ),
                el('div', { class: 'actions' }, ...actions),
            ),
            isWaiting
                ? el(
                      'div',
                      { class: 'waiting stack-sm', role: 'status' },
                      el(
                          'div',
                          { class: 'row' },
                          dot('live', true),
                          el('span', {
                              class: 'strong',
                              text: S.profile.waiting,
                          }),
                      ),
                      el('div', {
                          class: 'small muted',
                          text: S.profile.waitingHint(label),
                      }),
                      el(
                          'div',
                          { class: 'row' },
                          button(S.profile.cancelWait, {
                              onclick: () => {
                                  stopWaiting();
                                  paint();
                              },
                          }),
                      ),
                  )
                : null,
            note && note.provider === c.provider
                ? el('div', {
                      class: 'inline-error',
                      role: 'alert',
                      text: note.text,
                  })
                : null,
        );
    };

    function paint() {
        clear(card);
        card.append(
            el('h2', { text: S.profile.connections }),
            el('p', { class: 'small muted', text: S.profile.connectionsText }),
        );

        if (listError && !list) {
            card.append(
                errorState(
                    listError,
                    async () => {
                        listError = null;
                        paint();
                        await fetchList();
                        paint();
                    },
                    S.profile.connectionsFailed,
                ),
            );

            return;
        }

        if (!list) {
            card.append(skeleton(3));

            return;
        }

        card.append(el('ul', { class: 'rows' }, ...list.map(row)));
    }

    paint();
    fetchList().then(paint);

    return card;
}
