// Run with: node --test tests/js/menuGroups.test.ts
import assert from 'node:assert/strict';
import { describe, it } from 'node:test';
import {
    buildMenuGroups,
    filterMenuGroups,
    normalizeText,
} from '../../resources/js/lib/menuGroups.ts';
import { aggregateItems } from '../../resources/js/lib/receipt.ts';

const menu = [
    { key: 'a', name: 'Vorspeise: Samosa', priceCents: 450 },
    { key: 'b', name: 'Lamm: Lamb Curry', priceCents: 1299 },
    { key: 'c', name: 'Vorspeise: Pakora', priceCents: 500 },
    { key: 'd', name: 'Reis', priceCents: 300 },
    { key: 'e', name: 'Naan: Garlic Naan (vegetarisch)', priceCents: 350 },
];

describe('buildMenuGroups', () => {
    it('groups by prefix in order of first appearance, unprefixed to Weitere', () => {
        const { grouped, groups } = buildMenuGroups(menu);

        assert.equal(grouped, true);
        assert.deepEqual(
            groups.map((g) => [g.label, g.items.length]),
            [
                ['Vorspeise', 2],
                ['Lamm', 1],
                ['Weitere', 1],
                ['Naan', 1],
            ],
        );
        assert.equal(groups[0].items[0].label, 'Samosa');
        assert.equal(groups[2].items[0].label, 'Reis');
    });

    it('stays flat with fewer than two groups', () => {
        const { grouped, groups } = buildMenuGroups([
            { key: 'a', name: 'Pizza: Salami', priceCents: 800 },
            { key: 'b', name: 'Pizza: Funghi', priceCents: 800 },
        ]);

        assert.equal(grouped, false);
        assert.equal(groups.length, 1);
        assert.equal(groups[0].items[0].label, 'Pizza: Salami');
    });

    it('ignores prefixes longer than 32 characters', () => {
        const long = `${'x'.repeat(33)}: Dish`;
        const { groups } = buildMenuGroups([
            { key: 'a', name: long, priceCents: 1 },
            { key: 'b', name: 'Plain', priceCents: 1 },
        ]);

        assert.equal(groups.length, 1);
    });
});

describe('filterMenuGroups', () => {
    const { groups } = buildMenuGroups(menu);

    it('matches case- and umlaut-insensitively', () => {
        assert.equal(normalizeText('Käse'), 'kase');
        assert.deepEqual(
            filterMenuGroups(groups, 'LAMB').flatMap((g) =>
                g.items.map((i) => i.key),
            ),
            ['b'],
        );
    });

    it('matches category names and drops empty groups', () => {
        assert.equal(filterMenuGroups(groups, 'vorspeise')[0].items.length, 2);
        assert.deepEqual(filterMenuGroups(groups, 'zzz'), []);
    });
});

describe('aggregateItems', () => {
    it('merges identical dishes and keeps notes, included stays free', () => {
        const lines = aggregateItems([
            {
                id: 1,
                optionKey: 'a',
                note: 'scharf',
                priceCents: 450,
                paid: false,
                included: false,
            },
            {
                id: 4,
                optionKey: 'a',
                note: null,
                priceCents: 450,
                paid: false,
                included: false,
            },
            {
                id: 2,
                optionKey: 'b',
                note: null,
                priceCents: 1299,
                paid: false,
                included: true,
            },
        ]);

        assert.equal(lines.length, 2);
        assert.equal(lines[0].count, 2);
        assert.equal(lines[0].totalCents, 900);
        assert.equal(lines[0].newestItemId, 4);
        assert.deepEqual(lines[0].notes, ['scharf']);
        assert.equal(lines[1].totalCents, 0);
    });
});
