import type { FoodOrderItemDto } from '@/types/catering';

export interface ReceiptLine {
    optionKey: string | null;
    included: boolean;
    count: number;
    /** Sum of the line; 0 for included items. */
    totalCents: number;
    notes: string[];
    /** The most recently created item: what "remove one" cancels. */
    newestItemId: number;
}

/**
 * Display-only aggregation: identical dishes become one "2×" line. The server
 * still stores one item per order click.
 */
export function aggregateItems(items: FoodOrderItemDto[]): ReceiptLine[] {
    const lines = new Map<string, ReceiptLine>();

    for (const item of items) {
        const key = `${item.optionKey ?? ''}|${item.included ? 1 : 0}`;
        const line = lines.get(key) ?? {
            optionKey: item.optionKey,
            included: item.included,
            count: 0,
            totalCents: 0,
            notes: [],
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
