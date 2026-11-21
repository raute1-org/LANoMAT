// Run with: node --test tests/js/menuChoices.test.ts
import assert from 'node:assert/strict';
import { describe, it } from 'node:test';
import {
    adjust,
    canAdd,
    controlFor,
    filterOptions,
    groupsSummary,
    isComplete,
    pickedInGroup,
    selectOnly,
    toPayload,
} from '../../resources/js/lib/menuChoices.ts';
import {
    buildMenuGroups,
    normalizeText,
} from '../../resources/js/lib/menuGroups.ts';
import {
    aggregateItems,
    pickSignature,
} from '../../resources/js/lib/receipt.ts';

const main = {
    label: 'Hauptgericht',
    count: 2,
    allowRepeat: true,
    options: [
        { key: 'palak', label: 'Palak Mushroom', category: 'Vegetarisch' },
        { key: 'dal', label: 'Dal Palak', category: 'Vegetarisch' },
        { key: 'korma', label: 'Hähnchen Korma', category: 'Hähnchen' },
    ],
};
const side = {
    label: 'Beilage',
    count: 1,
    allowRepeat: false,
    options: [
        { key: 'reis', label: 'Reis', category: 'Beilage' },
        { key: 'naan', label: 'Naan', category: 'Beilage' },
    ],
};

describe('selection rules', () => {
    it('allows repeats up to the count and then stops', () => {
        let sel = adjust(main, {}, 'palak', 1);
        sel = adjust(main, sel, 'palak', 1);

        assert.equal(pickedInGroup(sel, 'Hauptgericht'), 2);
        assert.equal(canAdd(main, sel, 'dal'), false);
        assert.deepEqual(adjust(main, sel, 'dal', 1), sel);
    });

    it('refuses a repeat when the group forbids it', () => {
        const noRepeat = { ...main, allowRepeat: false };
        const sel = adjust(noRepeat, {}, 'palak', 1);

        assert.equal(canAdd(noRepeat, sel, 'palak'), false);
        assert.equal(canAdd(noRepeat, sel, 'dal'), true);
    });

    it('removes a pick and drops empty entries', () => {
        let sel = adjust(main, {}, 'palak', 1);
        sel = adjust(main, sel, 'palak', -1);

        assert.deepEqual(sel, { Hauptgericht: {} });
        assert.deepEqual(adjust(main, sel, 'palak', -1), sel);
    });

    it('replaces the pick of a single choice', () => {
        let sel = selectOnly(side, {}, 'reis');
        sel = selectOnly(side, sel, 'naan');

        assert.deepEqual(sel, { Beilage: { naan: 1 } });
    });

    it('is complete only when every group has exactly its count', () => {
        let sel = adjust(main, {}, 'palak', 1);
        sel = adjust(main, sel, 'dal', 1);

        assert.equal(isComplete([main, side], sel), false);
        sel = selectOnly(side, sel, 'reis');
        assert.equal(isComplete([main, side], sel), true);
        assert.equal(isComplete([], sel), false);
    });

    it('builds the payload in group and menu order with repeats expanded', () => {
        let sel = adjust(main, {}, 'dal', 1);
        sel = adjust(main, sel, 'dal', 1);
        sel = selectOnly(side, sel, 'naan');

        assert.deepEqual(toPayload([main, side], sel), [
            { group: 'Hauptgericht', option_key: 'dal' },
            { group: 'Hauptgericht', option_key: 'dal' },
            { group: 'Beilage', option_key: 'naan' },
        ]);
    });

    it('picks the control that fits the group', () => {
        assert.equal(controlFor(side), 'radio');
        assert.equal(controlFor(main), 'stepper');
        assert.equal(controlFor({ ...main, allowRepeat: false }), 'checkbox');
    });

    it('summarises groups and filters options without diacritics', () => {
        assert.equal(
            groupsSummary([main, side]),
            '2× Hauptgericht, 1× Beilage',
        );
        assert.deepEqual(
            filterOptions(main.options, 'hahnchen', normalizeText).map(
                (o) => o.key,
            ),
            ['korma'],
        );
        assert.equal(filterOptions(main.options, '', normalizeText).length, 3);
    });
});

const picks = (...names: string[]) =>
    names.map((name) => ({ group: 'Hauptgericht', optionKey: name, name }));

const item = (id: number, choices: ReturnType<typeof picks>) => ({
    id,
    optionKey: 'menu-2',
    note: null,
    priceCents: 2490,
    paid: false,
    included: false,
    choices,
});

describe('receipt grouping of set menus', () => {
    it('merges identical menus and shows the picks once', () => {
        const lines = aggregateItems([
            item(1, picks('Vegetarisch: Palak', 'Vegetarisch: Palak')),
            item(2, picks('Vegetarisch: Palak', 'Vegetarisch: Palak')),
        ]);

        assert.equal(lines.length, 1);
        assert.equal(lines[0].count, 2);
        assert.equal(lines[0].totalCents, 4980);
        assert.deepEqual(lines[0].parts, [{ name: 'Palak', count: 2 }]);
        assert.equal(lines[0].newestItemId, 2);
    });

    it('keeps menus with different picks on separate lines', () => {
        const lines = aggregateItems([
            item(1, picks('Curry: Palak', 'Curry: Dal')),
            item(2, picks('Curry: Palak', 'Curry: Korma')),
        ]);

        assert.equal(lines.length, 2);
    });

    it('ignores the order of the picks in the signature', () => {
        const a = item(1, [
            { group: 'A', optionKey: 'x', name: 'X' },
            { group: 'A', optionKey: 'y', name: 'Y' },
        ]);
        const b = item(2, [
            { group: 'A', optionKey: 'y', name: 'Y' },
            { group: 'A', optionKey: 'x', name: 'X' },
        ]);

        assert.equal(pickSignature(a), pickSignature(b));
        assert.equal(aggregateItems([a, b]).length, 1);
    });

    it('leaves ordinary dishes exactly as before', () => {
        const lines = aggregateItems([
            {
                id: 1,
                optionKey: 'cola',
                note: 'kalt',
                priceCents: 250,
                paid: false,
                included: false,
            },
            {
                id: 2,
                optionKey: 'cola',
                note: null,
                priceCents: 250,
                paid: false,
                included: false,
            },
        ]);

        assert.equal(lines.length, 1);
        assert.deepEqual(lines[0].parts, []);
        assert.equal(lines[0].signature, '');
        assert.deepEqual(lines[0].notes, ['kalt']);
    });
});

describe('menu groups', () => {
    it('carries choices through and defaults to none', () => {
        const { groups } = buildMenuGroups([
            { key: 'm', name: 'Menü: Box', priceCents: 2490, choices: [main] },
            { key: 'c', name: 'Getränke: Cola', priceCents: 250 },
        ]);

        assert.equal(groups[0].items[0].choices.length, 1);
        assert.deepEqual(groups[1].items[0].choices, []);
    });
});
