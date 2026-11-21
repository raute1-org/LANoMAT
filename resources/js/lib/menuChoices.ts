/**
 * Pure rules of the set-menu picker ("Menü für 2 Personen"): which picks are allowed, when the
 * selection is complete and what is sent to the server. The server validates again; this only
 * keeps the dialog honest.
 */
export interface ChoiceOptionDto {
    key: string;
    /** Dish name without its category prefix. */
    label: string;
    category: string;
}

export interface ChoiceGroupDto {
    label: string;
    count: number;
    allowRepeat: boolean;
    options: ChoiceOptionDto[];
}

/** Picks per group label and dish key. */
export type Selection = Record<string, Record<string, number>>;

// A type alias (not an interface) so Inertia accepts it as form data.
export type ChoicePayload = {
    group: string;
    option_key: string;
};

export const EMPTY_SELECTION: Selection = {};

export function pickedInGroup(selection: Selection, group: string): number {
    return Object.values(selection[group] ?? {}).reduce((sum, n) => sum + n, 0);
}

export function pickedOf(
    selection: Selection,
    group: string,
    key: string,
): number {
    return selection[group]?.[key] ?? 0;
}

export function canAdd(
    group: ChoiceGroupDto,
    selection: Selection,
    key: string,
): boolean {
    if (pickedInGroup(selection, group.label) >= group.count) {
        return false;
    }

    return group.allowRepeat || pickedOf(selection, group.label, key) < 1;
}

/** Adds (+1) or removes (-1) one pick; a no-op when the limits do not allow it. */
export function adjust(
    group: ChoiceGroupDto,
    selection: Selection,
    key: string,
    delta: 1 | -1,
): Selection {
    const current = pickedOf(selection, group.label, key);

    if (delta === 1 ? !canAdd(group, selection, key) : current === 0) {
        return selection;
    }

    const next = { ...(selection[group.label] ?? {}), [key]: current + delta };

    if (next[key] === 0) {
        delete next[key];
    }

    return { ...selection, [group.label]: next };
}

/** Single choice: picking another dish replaces the previous pick. */
export function selectOnly(
    group: ChoiceGroupDto,
    selection: Selection,
    key: string,
): Selection {
    return { ...selection, [group.label]: { [key]: 1 } };
}

export function isGroupComplete(
    group: ChoiceGroupDto,
    selection: Selection,
): boolean {
    return pickedInGroup(selection, group.label) === group.count;
}

export function isComplete(
    groups: ChoiceGroupDto[],
    selection: Selection,
): boolean {
    return (
        groups.length > 0 &&
        groups.every((group) => isGroupComplete(group, selection))
    );
}

/** The request body: one entry per pick, in group and menu order. */
export function toPayload(
    groups: ChoiceGroupDto[],
    selection: Selection,
): ChoicePayload[] {
    const payload: ChoicePayload[] = [];

    for (const group of groups) {
        for (const option of group.options) {
            for (
                let i = 0;
                i < pickedOf(selection, group.label, option.key);
                i++
            ) {
                payload.push({ group: group.label, option_key: option.key });
            }
        }
    }

    return payload;
}

/** "2× Hauptgericht, 1× Beilage" for the hint under a set menu. */
export function groupsSummary(groups: ChoiceGroupDto[]): string {
    return groups.map((group) => `${group.count}× ${group.label}`).join(', ');
}

/** Options that contain the query (diacritic- and case-insensitive); all of them for an empty query. */
export function filterOptions(
    options: ChoiceOptionDto[],
    query: string,
    normalize: (value: string) => string,
): ChoiceOptionDto[] {
    const needle = normalize(query);

    return needle === ''
        ? options
        : options.filter(
              (option) =>
                  normalize(option.label).includes(needle) ||
                  normalize(option.category).includes(needle),
          );
}

/** Which control fits the group: a radio list for one pick, checkboxes without repeats, steppers otherwise. */
export function controlFor(
    group: ChoiceGroupDto,
): 'radio' | 'checkbox' | 'stepper' {
    if (group.count === 1) {
        return 'radio';
    }

    return group.allowRepeat ? 'stepper' : 'checkbox';
}
