// Run with: node --test tests/js/menuFilters.test.ts
import assert from 'node:assert/strict';
import { describe, it } from 'node:test';
import {
    applyMenuFilters,
    countItems,
    hasActiveFilters,
    NO_FILTERS,
} from '../../resources/js/lib/menuFilters.ts';

const groups = [
    {
        id: 'g1',
        label: 'A',
        items: [
            { key: 'a', label: 'Cheap', priceCents: 900 },
            { key: 'b', label: 'Mid', priceCents: 1000 },
        ],
    },
    {
        id: 'g2',
        label: 'B',
        items: [{ key: 'c', label: 'Pricey', priceCents: 1499 }],
    },
];

describe('applyMenuFilters', () => {
    it('returns the input untouched without filters', () => {
        assert.equal(applyMenuFilters(groups, NO_FILTERS, new Set()), groups);
        assert.equal(hasActiveFilters(NO_FILTERS), false);
    });

    it('filters by price inclusively and drops empty groups', () => {
        const result = applyMenuFilters(
            groups,
            { mine: false, maxPriceCents: 1000 },
            new Set(),
        );
        assert.deepEqual(
            result.map((g) => g.id),
            ['g1'],
        );
        assert.equal(countItems(result), 2);
    });

    it('filters to my selection', () => {
        const result = applyMenuFilters(
            groups,
            { mine: true, maxPriceCents: null },
            new Set(['c']),
        );
        assert.deepEqual(
            result.map((g) => g.id),
            ['g2'],
        );
    });

    it('combines both filters', () => {
        const result = applyMenuFilters(
            groups,
            { mine: true, maxPriceCents: 1000 },
            new Set(['b', 'c']),
        );
        assert.equal(countItems(result), 1);
        assert.equal(result[0].items[0].key, 'b');
    });
});
