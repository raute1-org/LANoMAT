<script setup lang="ts">
import { Check, LoaderCircle, Minus, Plus, Search } from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { formatEuro } from '@/lib/formatEuro';
import {
    adjust,
    canAdd,
    controlFor,
    EMPTY_SELECTION,
    filterOptions,
    isComplete,
    isGroupComplete,
    pickedInGroup,
    pickedOf,
    selectOnly,
    toPayload,
} from '@/lib/menuChoices';
import type {
    ChoiceGroupDto,
    ChoicePayload,
    Selection,
} from '@/lib/menuChoices';
import { normalizeText } from '@/lib/menuGroups';

/** Search is only offered when a group has more options than this. */
const SEARCH_THRESHOLD = 8;

const props = defineProps<{
    open: boolean;
    dish: string;
    priceCents: number;
    groups: ChoiceGroupDto[];
    note: string;
    pending: boolean;
    labels: Record<string, string>;
}>();

const emit = defineEmits<{
    'update:open': [value: boolean];
    confirm: [payload: { choices: ChoicePayload[]; note: string }];
}>();

const selection = ref<Selection>({ ...EMPTY_SELECTION });
const queries = ref<Record<string, string>>({});
const localNote = ref('');

// A fresh dialog every time: nothing from the previous menu is left over.
watch(
    () => props.open,
    (open) => {
        if (open) {
            selection.value = { ...EMPTY_SELECTION };
            queries.value = {};
            localNote.value = props.note;
        }
    },
    { immediate: true },
);

const complete = computed(() => isComplete(props.groups, selection.value));
const unavailable = computed(() =>
    props.groups.some((group) => group.options.length === 0),
);
const missing = computed(() =>
    props.groups.reduce(
        (sum, group) =>
            sum + group.count - pickedInGroup(selection.value, group.label),
        0,
    ),
);

const statusText = computed(() =>
    complete.value
        ? props.labels.menu_choose_complete
        : props.labels.menu_choose_missing.replace(
              ':missing',
              String(missing.value),
          ),
);

function progress(group: ChoiceGroupDto): string {
    return props.labels.menu_choose_progress
        .replace(':picked', String(pickedInGroup(selection.value, group.label)))
        .replace(':count', String(group.count));
}

function visibleOptions(group: ChoiceGroupDto) {
    return filterOptions(
        group.options,
        queries.value[group.label] ?? '',
        normalizeText,
    );
}

/** The category is only worth showing when the group mixes several. */
function showCategory(group: ChoiceGroupDto): boolean {
    return new Set(group.options.map((option) => option.category)).size > 1;
}

function groupId(index: number, suffix: string): string {
    return `menu-choice-${index}-${suffix}`;
}

function onStep(group: ChoiceGroupDto, key: string, delta: 1 | -1): void {
    selection.value = adjust(group, selection.value, key, delta);
}

function onToggle(group: ChoiceGroupDto, key: string): void {
    selection.value =
        pickedOf(selection.value, group.label, key) > 0
            ? adjust(group, selection.value, key, -1)
            : adjust(group, selection.value, key, 1);
}

function onSelect(group: ChoiceGroupDto, key: string): void {
    selection.value = selectOnly(group, selection.value, key);
}

function confirm(): void {
    if (!complete.value || props.pending) {
        return;
    }

    emit('confirm', {
        choices: toPayload(props.groups, selection.value),
        note: localNote.value,
    });
}

const rowClass =
    'flex min-h-11 items-center gap-3 rounded-md px-2 py-1 text-sm hover:bg-accent focus-within:bg-accent pointer-coarse:min-h-12';
</script>

<template>
    <Dialog :open="open" @update:open="(value) => emit('update:open', value)">
        <DialogContent
            class="flex max-h-[90dvh] flex-col gap-0 p-0 sm:max-w-xl"
            data-test="menu-choice-dialog"
        >
            <DialogHeader class="shrink-0 border-b border-border p-4 pr-12">
                <DialogTitle>{{ labels.menu_choose_title }}</DialogTitle>
                <DialogDescription>
                    {{ labels.menu_choose_help.replace(':dish', dish) }}
                </DialogDescription>
            </DialogHeader>

            <div class="min-h-0 flex-1 space-y-6 overflow-y-auto p-4">
                <p
                    v-if="unavailable"
                    class="rounded-md border border-border bg-card px-3 py-2 text-sm text-muted-foreground"
                    data-test="menu-choice-unavailable"
                >
                    {{ labels.menu_choose_empty }}
                </p>

                <fieldset
                    v-for="(group, index) in groups"
                    :key="group.label"
                    class="min-w-0 space-y-2"
                    :aria-describedby="groupId(index, 'progress')"
                    :data-test="`menu-choice-group-${index}`"
                >
                    <legend class="float-left w-full text-sm font-semibold">
                        {{ group.label }}
                    </legend>
                    <p
                        :id="groupId(index, 'progress')"
                        :class="[
                            'clear-both font-mono text-xs tabular-nums',
                            isGroupComplete(group, selection)
                                ? 'text-foreground'
                                : 'text-muted-foreground',
                        ]"
                        data-test="menu-choice-progress"
                    >
                        <Check
                            v-if="isGroupComplete(group, selection)"
                            class="mr-1 inline size-3.5"
                            aria-hidden="true"
                        />{{ progress(group) }}
                    </p>

                    <div
                        v-if="group.options.length > SEARCH_THRESHOLD"
                        class="relative"
                    >
                        <Search
                            class="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-muted-foreground"
                            aria-hidden="true"
                        />
                        <Input
                            type="search"
                            class="pl-9"
                            :model-value="queries[group.label] ?? ''"
                            :placeholder="labels.menu_choose_search"
                            :aria-label="`${group.label}: ${labels.menu_choose_search}`"
                            @update:model-value="
                                (value) =>
                                    (queries[group.label] = String(value))
                            "
                        />
                    </div>

                    <ul
                        class="divide-y divide-border rounded-md border border-border"
                        :aria-label="group.label"
                    >
                        <li
                            v-for="option in visibleOptions(group)"
                            :key="option.key"
                        >
                            <label
                                v-if="controlFor(group) === 'radio'"
                                :class="[rowClass, 'cursor-pointer']"
                            >
                                <input
                                    type="radio"
                                    class="size-5 shrink-0 accent-primary"
                                    :name="`group-${index}`"
                                    :checked="
                                        pickedOf(
                                            selection,
                                            group.label,
                                            option.key,
                                        ) > 0
                                    "
                                    @change="onSelect(group, option.key)"
                                />
                                <span class="min-w-0 flex-1 break-words">
                                    {{ option.label }}
                                </span>
                                <span
                                    v-if="showCategory(group)"
                                    class="shrink-0 text-xs text-muted-foreground"
                                    >{{ option.category }}</span
                                >
                            </label>

                            <label
                                v-else-if="controlFor(group) === 'checkbox'"
                                :class="[rowClass, 'cursor-pointer']"
                            >
                                <input
                                    type="checkbox"
                                    class="size-5 shrink-0 accent-primary"
                                    :checked="
                                        pickedOf(
                                            selection,
                                            group.label,
                                            option.key,
                                        ) > 0
                                    "
                                    :disabled="
                                        pickedOf(
                                            selection,
                                            group.label,
                                            option.key,
                                        ) === 0 &&
                                        !canAdd(group, selection, option.key)
                                    "
                                    @change="onToggle(group, option.key)"
                                />
                                <span class="min-w-0 flex-1 break-words">
                                    {{ option.label }}
                                </span>
                                <span
                                    v-if="showCategory(group)"
                                    class="shrink-0 text-xs text-muted-foreground"
                                    >{{ option.category }}</span
                                >
                            </label>

                            <div v-else :class="rowClass">
                                <span class="min-w-0 flex-1 break-words">
                                    {{ option.label }}
                                    <span
                                        v-if="showCategory(group)"
                                        class="ml-1 text-xs text-muted-foreground"
                                        >{{ option.category }}</span
                                    >
                                </span>
                                <Button
                                    type="button"
                                    variant="ghost"
                                    size="icon"
                                    class="size-10 shrink-0"
                                    :disabled="
                                        pickedOf(
                                            selection,
                                            group.label,
                                            option.key,
                                        ) === 0
                                    "
                                    :aria-label="
                                        labels.menu_choose_less.replace(
                                            ':dish',
                                            option.label,
                                        )
                                    "
                                    @click="onStep(group, option.key, -1)"
                                >
                                    <Minus aria-hidden="true" />
                                </Button>
                                <span
                                    class="w-5 shrink-0 text-center font-mono tabular-nums"
                                    role="status"
                                    :aria-label="`${option.label}: ${pickedOf(selection, group.label, option.key)}`"
                                    >{{
                                        pickedOf(
                                            selection,
                                            group.label,
                                            option.key,
                                        )
                                    }}</span
                                >
                                <Button
                                    type="button"
                                    variant="outline"
                                    size="icon"
                                    class="size-10 shrink-0"
                                    :disabled="
                                        !canAdd(group, selection, option.key)
                                    "
                                    :aria-label="
                                        labels.menu_choose_more.replace(
                                            ':dish',
                                            option.label,
                                        )
                                    "
                                    @click="onStep(group, option.key, 1)"
                                >
                                    <Plus aria-hidden="true" />
                                </Button>
                            </div>
                        </li>
                        <li
                            v-if="visibleOptions(group).length === 0"
                            class="px-3 py-3 text-sm text-muted-foreground"
                        >
                            {{ labels.menu_choose_no_match }}
                        </li>
                    </ul>
                </fieldset>

                <div class="space-y-1.5">
                    <label class="text-sm font-medium" for="menu-choice-note">
                        {{ labels.note_label }}
                    </label>
                    <Input
                        id="menu-choice-note"
                        v-model="localNote"
                        maxlength="200"
                        :placeholder="labels.note_placeholder"
                    />
                </div>
            </div>

            <DialogFooter
                class="shrink-0 gap-2 border-t border-border p-4 sm:items-center"
            >
                <p
                    class="text-sm text-muted-foreground sm:mr-auto"
                    role="status"
                    aria-live="polite"
                    data-test="menu-choice-status"
                >
                    {{ statusText }}
                </p>
                <Button
                    type="button"
                    variant="outline"
                    @click="emit('update:open', false)"
                >
                    {{ labels.menu_choose_cancel }}
                </Button>
                <Button
                    type="button"
                    :disabled="!complete || unavailable || pending"
                    :aria-busy="pending"
                    data-test="menu-choice-confirm"
                    @click="confirm"
                >
                    <LoaderCircle
                        v-if="pending"
                        class="animate-spin motion-reduce:animate-none"
                        aria-hidden="true"
                    />
                    {{
                        labels.menu_choose_confirm.replace(
                            ':price',
                            formatEuro(priceCents),
                        )
                    }}
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
