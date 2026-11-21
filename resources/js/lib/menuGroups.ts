/**
 * Client-side menu grouping for long food menus (see docs/design.md, "Long
 * menus"). An imported menu carries the category as a name prefix
 * ("Lamm: Lamb Curry"); the server stays flat and the page derives groups here.
 */
import type { ChoiceGroupDto } from '@/lib/menuChoices';

export interface MenuItemInput {
    key: string;
    name: string;
    priceCents: number;
    description?: string | null;
    imageUrl?: string | null;
    choices?: ChoiceGroupDto[];
}

export interface MenuGroupItem {
    key: string;
    /** Dish name without the category prefix. */
    label: string;
    priceCents: number;
    description: string | null;
    imageUrl: string | null;
    /** Selection groups of a set menu; empty for an ordinary dish. */
    choices: ChoiceGroupDto[];
}

export interface MenuGroup {
    /** Stable id fragment for anchor links. */
    id: string;
    label: string;
    items: MenuGroupItem[];
}

export const OTHER_GROUP_LABEL = 'Weitere';
export const MAX_PREFIX_LENGTH = 32;

const SEPARATOR = ': ';

/** Lower-cases and strips diacritics so "Käse" matches "kase" and "KASE". */
export function normalizeText(value: string): string {
    return value
        .normalize('NFD')
        .replace(/\p{M}/gu, '')
        .toLocaleLowerCase('de-DE')
        .trim();
}

function splitPrefix(name: string): { prefix: string | null; label: string } {
    const index = name.indexOf(SEPARATOR);

    if (index <= 0 || index > MAX_PREFIX_LENGTH) {
        return { prefix: null, label: name };
    }

    const label = name.slice(index + SEPARATOR.length).trim();

    return label === ''
        ? { prefix: null, label: name }
        : { prefix: name.slice(0, index).trim(), label };
}

/**
 * Groups by name prefix in order of first appearance. Entries without a prefix
 * land in "Weitere" (placed where it first occurs). Returns a single unlabeled
 * flat group when fewer than two groups would result.
 */
export function buildMenuGroups(menu: MenuItemInput[]): {
    grouped: boolean;
    groups: MenuGroup[];
} {
    const byLabel = new Map<string, MenuGroup>();

    for (const option of menu) {
        const { prefix, label } = splitPrefix(option.name);
        const groupLabel = prefix ?? OTHER_GROUP_LABEL;
        let group = byLabel.get(groupLabel);

        if (group === undefined) {
            group = { id: '', label: groupLabel, items: [] };
            byLabel.set(groupLabel, group);
        }

        group.items.push({
            key: option.key,
            label: prefix === null ? option.name : label,
            priceCents: option.priceCents,
            description: option.description ?? null,
            imageUrl: option.imageUrl ?? null,
            choices: option.choices ?? [],
        });
    }

    if (byLabel.size < 2) {
        return {
            grouped: false,
            groups: [
                {
                    id: 'all',
                    label: '',
                    items: menu.map((option) => ({
                        key: option.key,
                        label: option.name,
                        priceCents: option.priceCents,
                        description: option.description ?? null,
                        imageUrl: option.imageUrl ?? null,
                        choices: option.choices ?? [],
                    })),
                },
            ],
        };
    }

    const groups = [...byLabel.values()];
    groups.forEach((group, index) => {
        group.id = `g${index + 1}`;
    });

    return { grouped: true, groups };
}

/** Keeps matching dishes (name, description and category match); drops empty groups. */
export function filterMenuGroups(
    groups: MenuGroup[],
    query: string,
): MenuGroup[] {
    const needle = normalizeText(query);

    if (needle === '') {
        return groups;
    }

    return groups
        .map((group) => {
            const groupMatches = normalizeText(group.label).includes(needle);

            return {
                ...group,
                items: groupMatches
                    ? group.items
                    : group.items.filter(
                          (item) =>
                              normalizeText(item.label).includes(needle) ||
                              normalizeText(item.description ?? '').includes(
                                  needle,
                              ),
                      ),
            };
        })
        .filter((group) => group.items.length > 0);
}
