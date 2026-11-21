import type { FoodOrderItemDto } from '@/types/catering';

const PREFIX_SEPARATOR = ': ';
const MAX_PREFIX_LENGTH = 32;

/**
 * A pick snapshot without its category prefix ("Curry: Palak" becomes "Palak"); the same rule as
 * the menu grouping and the server's GroupOrder::split. Kept local so this module stays free of
 * imports (it is unit-tested with plain node).
 */
export function dishLabel(name: string): string {
    const index = name.indexOf(PREFIX_SEPARATOR);

    if (index <= 0 || index > MAX_PREFIX_LENGTH) {
        return name;
    }

    const label = name.slice(index + PREFIX_SEPARATOR.length).trim();

    return label === '' ? name : label;
}

export interface ReceiptPart {
    name: string;
    count: number;
}

export interface ReceiptLine {
    optionKey: string | null;
    included: boolean;
    /** Identifies the picks of a set menu; empty for an ordinary dish. */
    signature: string;
    count: number;
    /** Sum of the line; 0 for included items. */
    totalCents: number;
    notes: string[];
    /** The picks of ONE menu of this line (a set menu), counted per dish. */
    parts: ReceiptPart[];
    /** The most recently created item: what "remove one" cancels. */
    newestItemId: number;
}

/** Same menu with the same picks gives the same signature, regardless of pick order. */
export function pickSignature(item: FoodOrderItemDto): string {
    return (item.choices ?? [])
        .map((pick) => `${pick.group}\u0000${pick.optionKey}`)
        .sort()
        .join('\u0001');
}

export function lineKeyOf(line: {
    optionKey: string | null;
    included: boolean;
    signature: string;
}): string {
    return `${line.optionKey ?? ''}|${line.included ? 1 : 0}|${line.signature}`;
}

function countParts(item: FoodOrderItemDto): ReceiptPart[] {
    const counted = new Map<string, number>();

    for (const pick of item.choices ?? []) {
        const name = dishLabel(pick.name);
        counted.set(name, (counted.get(name) ?? 0) + 1);
    }

    return [...counted.entries()]
        .map(([name, count]) => ({ name, count }))
        .sort((a, b) => b.count - a.count || a.name.localeCompare(b.name));
}

/**
 * Display-only aggregation: identical dishes become one "2×" line; a set menu is only merged with
 * a set menu of the same picks, different picks stay separate lines. The server still stores one
 * item per order click.
 */
export function aggregateItems(items: FoodOrderItemDto[]): ReceiptLine[] {
    const lines = new Map<string, ReceiptLine>();

    for (const item of items) {
        const signature = pickSignature(item);
        const key = lineKeyOf({
            optionKey: item.optionKey,
            included: item.included,
            signature,
        });
        const line = lines.get(key) ?? {
            optionKey: item.optionKey,
            included: item.included,
            signature,
            count: 0,
            totalCents: 0,
            notes: [],
            parts: countParts(item),
            newestItemId: item.id,
        };

        line.count += 1;
        line.totalCents += item.included ? 0 : item.priceCents;
        line.newestItemId = Math.max(line.newestItemId, item.id);

        if (item.note) {
            line.notes.push(item.note);
        }

        lines.set(key, line);
    }

    return [...lines.values()];
}
/** The newest item id of a dish, or null when the dish is not ordered. */
export function newestItemIdFor(
    items: FoodOrderItemDto[],
    optionKey: string,
): number | null {
    const ids = items
        .filter((item) => item.optionKey === optionKey)
        .map((item) => item.id);

    return ids.length === 0 ? null : Math.max(...ids);
}
