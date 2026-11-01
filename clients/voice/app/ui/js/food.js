// Essen: the menu per ordering round with quantity steppers and a running total (integer
// cents), item-based ordering as the API offers it, and my orders with a short status timeline.
import { pageHeader } from './nav.js';
import { eventSlug, noEventState } from './shared.js';
import { S } from './strings.js';
import {
    button,
    call,
    chip,
    clear,
    clock,
    dot,
    el,
    emptyState,
    errorState,
    euro,
    plainMessage,
    relative,
    say,
    skeleton,
    staleHint,
    toast,
} from './util.js';

const MAX_QTY = 20;
let root = null;
let segBox = null;
let body = null;
let sub = 'menu';
const menu = { phase: 'loading', data: null, error: null };
const mine = { phase: 'loading', data: null, error: null };
const sel = new Map(); // "orderId:key" -> quantity
const lineMsg = new Map(); // "orderId:key" -> message
let busy = false;

export function init(section) {
    root = section;
    segBox = el('div', {
        class: 'seg',
        role: 'group',
        'aria-label': S.food.sections,
    });
    body = el('div', { class: 'stack' });
    root.append(pageHeader('essen'), segBox, body);
}

export async function show(opts = {}) {
    if (opts.card === 'orders' || opts.sub === 'orders') {
        sub = 'orders';
    }

    paintSeg();
    render();
    await Promise.all([loadMenu(), loadMine()]);
}

export function hide() {}

export function focus(opts) {
    show(opts);
}

function paintSeg() {
    clear(segBox);
    const n = mine.data ? mine.data.items.length : 0;

    for (const [id, label] of [
        ['menu', S.food.sub.menu],
        ['orders', n ? `${S.food.sub.orders} (${n})` : S.food.sub.orders],
    ]) {
        segBox.append(
            el('button', {
                class: 'seg-btn',
                type: 'button',
                'aria-pressed': String(id === sub),
                text: label,
                onclick: () => {
                    sub = id;
                    paintSeg();
                    render();
                },
            }),
        );
    }
}

async function loadMenu() {
    const slug = eventSlug();

    if (!slug) {
        render();

        return;
    }

    if (menu.phase !== 'ready') {
        menu.phase = 'loading';
        render();
    }

    const r = await call('get_catering', { event: slug });

    if (r.ok) {
        menu.data = r.value;
        menu.phase = 'ready';
        menu.error = null;
        menu.stale = false;
    } else if (menu.phase !== 'ready') {
        menu.phase = 'error';
        menu.error = r.error;
    } else {
        menu.stale = true;
    }

    render();
}

async function loadMine() {
    const slug = eventSlug();

    if (!slug) {
        return;
    }

    const r = await call('my_catering_items', { event: slug });

    if (r.ok) {
        mine.data = r.value;
        mine.phase = 'ready';
        mine.error = null;
        mine.stale = false;
    } else if (mine.phase !== 'ready') {
        mine.phase = 'error';
        mine.error = r.error;
    } else {
        mine.stale = true;
    }

    paintSeg();
    render();
}

function render() {
    if (!body) {
        return;
    }

    clear(body);

    if (!eventSlug()) {
        body.append(noEventState());

        return;
    }

    const d = sub === 'menu' ? menu : mine;

    if (d.phase === 'loading') {
        body.append(skeleton(3));

        return;
    }

    if (d.phase === 'error') {
        body.append(errorState(d.error, sub === 'menu' ? loadMenu : loadMine));

        return;
    }

    if (d.stale) {
        body.append(staleHint(sub === 'menu' ? loadMenu : loadMine));
    }

    if (sub === 'menu') {
        renderMenu();
    } else {
        renderMine();
    }
}

// ---- menu ----

function orderWindow(o) {
    if (o.is_open) {
        return o.closes_at
            ? [S.food.open(clock(o.closes_at), relative(o.closes_at)), 'ok']
            : [S.food.openNoEnd, 'ok'];
    }

    if (
        o.status === 'open' &&
        o.opens_at &&
        new Date(o.opens_at) > new Date()
    ) {
        return [S.food.opensAt(clock(o.opens_at)), 'warn'];
    }

    return [S.food.closed, ''];
}

const qtyOf = (k) => sel.get(k) || 0;

function selectionOf(o) {
    let count = 0;
    let cents = 0;

    for (const item of o.menu || []) {
        const q = qtyOf(`${o.id}:${item.key}`);
        count += q;
        cents += q * item.price_cents;
    }

    return { count, cents };
}

function renderMenu() {
    const d = menu.data;
    const orders = d.orders || [];

    if (!orders.length) {
        body.append(emptyState(S.food.noOrders, S.food.noOrdersHint));

        return;
    }

    const lim = d.limits || {};

    if (lim.items_per_user) {
        body.append(
            el('div', {
                class: 'hint',
                text: S.food.limit(lim.my_open_items || 0, lim.items_per_user),
            }),
        );
    }

    for (const o of orders) {
        body.append(orderCard(o));
    }
}

function stepper(o, item, enabled) {
    const key = `${o.id}:${item.key}`;
    const q = qtyOf(key);
    const set = (n) => {
        sel.set(key, Math.max(0, Math.min(MAX_QTY, n)));
        lineMsg.delete(key);
        render();
        const again = document.querySelector(
            `[data-step="${CSS.escape(key)}"]`,
        );

        if (again) {
            again.focus();
        }
    };

    return el(
        'div',
        {
            class: 'stepper',
            role: 'group',
            'aria-label': S.food.quantityOf(item.name),
        },
        el('button', {
            class: 'btn step',
            type: 'button',
            text: '−',
            'aria-label': S.food.less(item.name),
            disabled: !enabled || q === 0,
            onclick: () => set(q - 1),
        }),
        el('span', {
            class: 'qty mono',
            text: String(q),
            'aria-live': 'polite',
        }),
        el('button', {
            class: 'btn step',
            type: 'button',
            text: '+',
            'data-step': key,
            'aria-label': S.food.more(item.name),
            disabled: !enabled || q >= MAX_QTY,
            onclick: () => set(q + 1),
        }),
    );
}

async function placeLine(o, item) {
    const key = `${o.id}:${item.key}`;
    const qty = qtyOf(key);

    if (!qty || busy) {
        return;
    }

    busy = true;
    lineMsg.delete(key);
    render();
    let placed = 0;
    let failure = null;

    for (let i = 0; i < qty; i++) {
        const r = await call('place_food_item', {
            orderId: o.id,
            optionKey: item.key,
        });

        if (!r.ok) {
            failure = r.error;
            break;
        }

        placed++;
    }

    busy = false;
    sel.set(key, qty - placed);

    if (placed) {
        toast(S.food.placed(placed, item.name));
    }

    if (failure) {
        lineMsg.set(key, plainMessage(failure));
        say(plainMessage(failure));
    }

    await Promise.all([loadMenu(), loadMine()]);
}

async function placeAll(o) {
    for (const item of o.menu || []) {
        if (
            qtyOf(`${o.id}:${item.key}`) &&
            !lineMsg.has(`${o.id}:${item.key}`)
        ) {
            await placeLine(o, item);
        }
    }
}

function orderCard(o) {
    const [windowText, tone] = orderWindow(o);
    const enabled = o.is_open && !busy;
    const total = selectionOf(o);
    const lines = (o.menu || []).map((item) => {
        const key = `${o.id}:${item.key}`;
        const q = qtyOf(key);
        const msg = lineMsg.get(key);

        return el(
            'li',
            { class: 'menu-line' },
            el(
                'div',
                { class: 'menu-row' },
                el(
                    'div',
                    { class: 'grow' },
                    el('div', { class: 'name', text: item.name }),
                ),
                el('span', {
                    class: 'price mono',
                    text: euro(item.price_cents),
                }),
                stepper(o, item, enabled),
                button(S.food.order, {
                    kind: q ? 'primary' : '',
                    disabled: !enabled || q === 0,
                    onclick: () => placeLine(o, item),
                }),
            ),
            msg
                ? el('div', { class: 'inline-error', role: 'alert', text: msg })
                : null,
        );
    });
    const mineHere = (o.my_items || []).length;

    return el(
        'article',
        { class: 'card order-round', 'aria-label': o.title },
        el(
            'div',
            { class: 'row between wrap' },
            el('div', { class: 'row' }, dot(tone), el('h2', { text: o.title })),
            chip(windowText, tone),
        ),
        mineHere
            ? el('div', {
                  class: 'small muted',
                  text: S.food.mineHere(mineHere, euro(o.my_total_cents || 0)),
              })
            : null,
        lines.length
            ? el('ul', { class: 'menu' }, ...lines)
            : el('div', { class: 'small muted', text: S.food.emptyMenu }),
        o.is_open && lines.length
            ? el(
                  'div',
                  { class: 'row between wrap summary' },
                  el('div', {
                      class: 'sum',
                      'aria-live': 'polite',
                      text: total.count
                          ? S.food.selection(total.count, euro(total.cents))
                          : S.food.nothingSelected,
                  }),
                  button(S.food.orderAll, {
                      kind: 'primary',
                      disabled: busy || total.count === 0,
                      onclick: () => placeAll(o),
                  }),
              )
            : !o.is_open
              ? el('div', { class: 'hint', text: S.food.closedHint })
              : null,
    );
}

// ---- my orders ----

function timeline(item) {
    const steps = [
        [S.food.step.placed, true],
        [S.food.step.closed, !item.order_is_open],
        [S.food.step.paid, Boolean(item.paid)],
    ];

    return el(
        'ol',
        { class: 'timeline', 'aria-label': S.food.step.label },
        ...steps.map(([label, doneStep]) =>
            el(
                'li',
                { class: doneStep ? 'done' : '' },
                dot(doneStep ? 'ok' : ''),
                el('span', { text: label }),
            ),
        ),
    );
}

async function cancel(item, btn) {
    btn.disabled = true;
    const r = await call('cancel_food_item', { itemId: item.id });

    if (!r.ok) {
        btn.disabled = false;
        toast(plainMessage(r.error), 'error');
        await loadMine();

        return;
    }

    toast(S.food.cancelled(item.option_name));
    await Promise.all([loadMenu(), loadMine()]);
}

function renderMine() {
    const d = mine.data;
    const items = d.items || [];

    if (!items.length) {
        body.append(
            emptyState(
                S.food.noneYet,
                S.food.noneYetHint,
                button(S.food.toMenu, {
                    onclick: () => {
                        sub = 'menu';
                        paintSeg();
                        render();
                    },
                }),
            ),
        );

        return;
    }

    const groups = new Map();

    for (const it of items) {
        if (!groups.has(it.order_id)) {
            groups.set(it.order_id, []);
        }

        groups.get(it.order_id).push(it);
    }

    for (const list of groups.values()) {
        const first = list[0];
        body.append(
            el(
                'article',
                { class: 'card order-mine', 'aria-label': first.order_title },
                el(
                    'div',
                    { class: 'row between wrap' },
                    el('h2', { text: first.order_title }),
                    chip(
                        first.order_is_open ? S.food.stillOpen : S.food.closed,
                        first.order_is_open ? 'ok' : '',
                    ),
                ),
                el(
                    'ul',
                    { class: 'menu' },
                    ...list.map((it) =>
                        el(
                            'li',
                            { class: 'menu-line' },
                            el(
                                'div',
                                { class: 'menu-row' },
                                el(
                                    'div',
                                    { class: 'grow' },
                                    el('div', {
                                        class: 'name',
                                        text: it.option_name,
                                    }),
                                    it.note
                                        ? el('div', {
                                              class: 'small muted',
                                              text: it.note,
                                          })
                                        : null,
                                ),
                                el('span', {
                                    class: 'price mono',
                                    text: euro(it.price_cents),
                                }),
                                it.can_cancel
                                    ? el('button', {
                                          class: 'btn danger',
                                          type: 'button',
                                          text: S.food.cancel,
                                          'aria-label': S.food.cancelAria(
                                              it.option_name,
                                          ),
                                          onclick: (ev) =>
                                              cancel(it, ev.currentTarget),
                                      })
                                    : null,
                            ),
                            timeline(it),
                            !it.can_cancel
                                ? el('div', {
                                      class: 'small muted',
                                      text: it.paid
                                          ? S.food.noCancelPaid
                                          : S.food.noCancelClosed,
                                  })
                                : null,
                        ),
                    ),
                ),
            ),
        );
    }

    body.append(
        el(
            'div',
            { class: 'card total' },
            el(
                'div',
                { class: 'row between' },
                el('span', { text: S.food.total }),
                el('strong', { class: 'mono', text: euro(d.total_cents || 0) }),
            ),
            el(
                'div',
                { class: 'row between small muted' },
                el('span', { text: S.food.paid }),
                el('span', { class: 'mono', text: euro(d.paid_cents || 0) }),
            ),
        ),
    );
}
