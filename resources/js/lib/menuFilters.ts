import type { MenuGroup } from '@/lib/menuGroups';

export interface MenuFilters {
    /** Only dishes already on my receipt. */
    mine: boolean;
    /** Only dishes up to this price in cents; null means no price filter. */
    maxPriceCents: number | null;
}

export const NO_FILTERS: MenuFilters = { mine: false, maxPriceCents: null };

export function hasActiveFilters(filters: MenuFilters): boolean {
    return filters.mine || filters.maxPriceCents !== null;
}

/** Applies the chip filters to already grouped (and searched) dishes; drops empty groups. */
export function applyMenuFilters(
    groups: MenuGroup[],
    filters: MenuFilters,
    selectedKeys: ReadonlySet<string>,
): MenuGroup[] {
    if (!hasActiveFilters(filters)) {
        return groups;
    }

    return groups
        .map((group) => ({
            ...group,
            items: group.items.filter(
                (item) =>
                    (!filters.mine || selectedKeys.has(item.key)) &&
                    (filters.maxPriceCents === null ||
                        item.priceCents <= filters.maxPriceCents),
            ),
        }))
        .filter((group) => group.items.length > 0);
}

export function countItems(groups: MenuGroup[]): number {
    return groups.reduce((sum, group) => sum + group.items.length, 0);
}
