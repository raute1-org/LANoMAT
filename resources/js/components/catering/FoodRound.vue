<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { Check, LoaderCircle, Minus, Plus, Search } from '@lucide/vue';
import { useMediaQuery } from '@vueuse/core';
import { computed, onBeforeUnmount, ref } from 'vue';
import FoodReceipt from '@/components/catering/FoodReceipt.vue';
import LiveIndicator from '@/components/LiveIndicator.vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import {
    Sheet,
    SheetContent,
    SheetDescription,
    SheetHeader,
    SheetTitle,
} from '@/components/ui/sheet';
import { formatInstant } from '@/lib/datetime';
import { formatEuro } from '@/lib/formatEuro';
import {
    applyMenuFilters,
    countItems,
    hasActiveFilters,
    NO_FILTERS,
} from '@/lib/menuFilters';
import type { MenuFilters } from '@/lib/menuFilters';
import { buildMenuGroups, filterMenuGroups } from '@/lib/menuGroups';
import { aggregateItems, newestItemIdFor } from '@/lib/receipt';
import {
    destroy as destroyItem,
    store as storeItem,
} from '@/routes/catering/items';
import type { FoodOrderDto } from '@/types';

const props = defineProps<{
    foodOrder: FoodOrderDto;
    labels: Record<string, string>;
    registrationRequired: boolean;
    primaryPayment: boolean;
}>();

const editable = computed(
    () => props.foodOrder.isOpen && !props.registrationRequired,
);

const menu = computed(() => buildMenuGroups(props.foodOrder.menu));
const query = ref('');
const filters = ref<MenuFilters>({ ...NO_FILTERS });
const filtersActive = computed(() => hasActiveFilters(filters.value));
const searched = computed(() =>
    filterMenuGroups(menu.value.groups, query.value),
);
const visibleGroups = computed(() =>
    applyMenuFilters(
        searched.value,
        filters.value,
        new Set(props.foodOrder.myItems.map((item) => item.optionKey ?? '')),
    ),
);
const visibleCount = computed(() => countItems(visibleGroups.value));
const totalDishes = computed(() => props.foodOrder.menu.length);
const narrowed = computed(
    () => query.value.trim() !== '' || filtersActive.value,
);

function togglePrice(cents: number): void {
    filters.value.maxPriceCents =
        filters.value.maxPriceCents === cents ? null : cents;
}

function resetFilters(): void {
    query.value = '';
    filters.value = { ...NO_FILTERS };
}

const priceChips = computed(() => [
    { cents: 1000, label: props.labels.filter_price_10 },
    { cents: 1500, label: props.labels.filter_price_15 },
]);

const chipClass = (active: boolean): string =>
    [
        'inline-flex min-h-9 items-center rounded-md border px-3 text-sm whitespace-nowrap outline-none focus-visible:ring-2 focus-visible:ring-ring pointer-coarse:min-h-11',
        active
            ? 'border-foreground bg-accent font-medium text-foreground'
            : 'border-border text-muted-foreground hover:bg-accent hover:text-foreground',
    ].join(' ');

function anchorId(groupId: string): string {
    return `round-${props.foodOrder.id}-${groupId}`;
}

const counts = computed(() => {
    const map = new Map<string, number>();

    for (const item of props.foodOrder.myItems) {
        if (item.optionKey !== null) {
            map.set(item.optionKey, (map.get(item.optionKey) ?? 0) + 1);
        }
    }

    return map;
});

function countFor(optionKey: string): number {
    return counts.value.get(optionKey) ?? 0;
}

function dishName(optionKey: string | null): string {
    if (optionKey === null) {
        return '';
    }

    // A dish that left the menu keeps the name snapshotted on the order.
    const full =
        props.foodOrder.menu.find((option) => option.key === optionKey)?.name ??
        props.foodOrder.myItems.find((item) => item.optionKey === optionKey)
            ?.name ??
        optionKey;

    // Show the dish without its category prefix when the menu is grouped.
    if (menu.value.grouped) {
        for (const group of menu.value.groups) {
            const item = group.items.find((entry) => entry.key === optionKey);

            if (item) {
                return item.label;
            }
        }
    }

    return full;
}

const itemCount = computed(() => props.foodOrder.myItems.length);
const totalCents = computed(() =>
    aggregateItems(props.foodOrder.myItems).reduce(
        (sum, line) => sum + line.totalCents,
        0,
    ),
);

function countLabel(): string {
    const template =
        itemCount.value === 1
            ? props.labels.dish_count_one
            : props.labels.dish_count_other;

    return template.replace(':count', String(itemCount.value));
}

// The note applies to the next added dish and is cleared after success.
const note = ref('');
const highlightKey = ref<string | null>(null);
let highlightTimer: ReturnType<typeof setTimeout> | undefined;

function highlight(optionKey: string | null): void {
    highlightKey.value = optionKey;
    clearTimeout(highlightTimer);
    highlightTimer = setTimeout(() => {
        highlightKey.value = null;
    }, 1200);
}

onBeforeUnmount(() => clearTimeout(highlightTimer));

// The controller answers a refused order with a redirect and an error toast,
// which Inertia still reports as success. Keep the note in that case.
function wasRefused(flash: Record<string, unknown> | undefined): boolean {
    const toast = flash?.toast as { type?: string } | undefined;

    return toast?.type === 'error';
}

// Keys with a request in flight (button disabled, spinner) and keys that were
// just ordered (check icon for a moment).
const pending = ref<Set<string>>(new Set());
const justAdded = ref<Set<string>>(new Set());
const announcement = ref('');

function mark(target: typeof pending, key: string, on: boolean): void {
    const next = new Set(target.value);

    if (on) {
        next.add(key);
    } else {
        next.delete(key);
    }

    target.value = next;
}

function add(optionKey: string): void {
    if (pending.value.has(optionKey)) {
        return;
    }

    mark(pending, optionKey, true);
    router.post(
        storeItem.url({ foodOrder: props.foodOrder.id }),
        { option_key: optionKey, note: note.value || undefined },
        {
            preserveScroll: true,
            onSuccess: (page) => {
                if (wasRefused(page.flash)) {
                    return;
                }

                note.value = '';
                highlight(optionKey);
                mark(justAdded, optionKey, true);
                setTimeout(() => mark(justAdded, optionKey, false), 1200);
                announce(
                    props.labels.toast_added
                        .replace(':dish', dishName(optionKey))
                        .replace(' · :price', ''),
                );
                bumpBar();
            },
            onFinish: () => mark(pending, optionKey, false),
        },
    );
}

function announce(message: string): void {
    // Toggle a trailing space so a repeated identical message is announced again.
    announcement.value =
        announcement.value === message ? `${message} ` : message;
}

// The mobile cart bar flashes briefly on every change.
const barBumped = ref(false);
let barTimer: ReturnType<typeof setTimeout> | undefined;

function bumpBar(): void {
    barBumped.value = true;
    clearTimeout(barTimer);
    barTimer = setTimeout(() => {
        barBumped.value = false;
    }, 1200);
}

onBeforeUnmount(() => clearTimeout(barTimer));

function removeItem(itemId: number): void {
    const item = props.foodOrder.myItems.find((entry) => entry.id === itemId);
    const key = item?.optionKey ?? null;

    router.delete(destroyItem.url({ foodOrderItem: itemId }), {
        preserveScroll: true,
        onSuccess: (page) => {
            if (wasRefused(page.flash)) {
                return;
            }

            if (key !== null) {
                highlight(key);
                announce(
                    props.labels.toast_removed.replace(':dish', dishName(key)),
                );
            }

            bumpBar();
        },
    });
}

function removeOne(optionKey: string): void {
    const id = newestItemIdFor(props.foodOrder.myItems, optionKey);

    if (id !== null) {
        removeItem(id);
    }
}

function formatDateTime(iso: string): string {
    return formatInstant(iso, { dateStyle: 'short', timeStyle: 'short' });
}

// Long descriptions are clamped to two lines; this is only a length heuristic for offering "Mehr".
const LONG_DESCRIPTION = 90;
const expanded = ref<Set<string>>(new Set());

function toggleExpanded(key: string): void {
    const next = new Set(expanded.value);

    if (next.has(key)) {
        next.delete(key);
    } else {
        next.add(key);
    }

    expanded.value = next;
}

// One controlled dialog for the whole round, not one per dish.
const photo = ref<{ label: string; url: string } | null>(null);
const photoOpen = computed({
    get: () => photo.value !== null,
    set: (open: boolean) => {
        if (!open) {
            photo.value = null;
        }
    },
});

const sheetOpen = ref(false);
// Mount exactly one receipt so ids, live regions and the pay button never double up.
const isDesktop = useMediaQuery('(min-width: 1024px)');
</script>

<template>
    <section
        class="space-y-4"
        :aria-labelledby="`round-title-${foodOrder.id}`"
        :data-test="`food-round-${foodOrder.id}`"
    >
        <p class="sr-only" role="status" aria-live="polite">
            {{ announcement }}
        </p>
        <div
            class="flex flex-wrap items-center justify-between gap-x-4 gap-y-1"
        >
            <div class="flex min-w-0 items-start gap-3">
                <img
                    v-if="foodOrder.restaurant?.logoUrl"
                    :src="foodOrder.restaurant.logoUrl"
                    alt=""
                    width="48"
                    height="48"
                    loading="lazy"
                    decoding="async"
                    class="size-12 shrink-0 rounded-md border border-border bg-card object-contain"
                />
                <div class="min-w-0">
                    <h2
                        :id="`round-title-${foodOrder.id}`"
                        class="text-xl font-semibold tracking-tight"
                    >
                        {{ foodOrder.title }}
                    </h2>
                    <p
                        v-if="
                            foodOrder.restaurant &&
                            foodOrder.restaurant.name !== foodOrder.title
                        "
                        class="text-sm font-medium"
                    >
                        {{ foodOrder.restaurant.name }}
                    </p>
                    <p
                        v-if="foodOrder.restaurant?.description"
                        class="max-w-prose text-sm text-muted-foreground"
                    >
                        {{ foodOrder.restaurant.description }}
                    </p>
                    <p
                        v-if="foodOrder.opensAt || foodOrder.closesAt"
                        class="text-sm text-muted-foreground"
                    >
                        <span v-if="foodOrder.opensAt">
                            {{ labels.opens_at }}
                            <span class="font-mono tabular-nums">{{
                                formatDateTime(foodOrder.opensAt)
                            }}</span>
                        </span>
                        <span v-if="foodOrder.opensAt && foodOrder.closesAt">
                            ·
                        </span>
                        <span
                            v-if="foodOrder.closesAt"
                            class="font-medium text-foreground"
                        >
                            {{ labels.closes_at }}
                            <span class="font-mono tabular-nums">{{
                                formatDateTime(foodOrder.closesAt)
                            }}</span>
                        </span>
                    </p>
                </div>
            </div>
            <LiveIndicator
                :variant="foodOrder.isOpen ? 'ok' : 'down'"
                :pulse="foodOrder.isOpen"
                :label="
                    foodOrder.isOpen ? labels.window_open : labels.window_closed
                "
            />
        </div>

        <p
            v-if="!foodOrder.isOpen"
            class="rounded-md border border-border bg-card px-4 py-3 text-sm text-muted-foreground"
            data-test="window-closed-hint"
        >
            {{ labels.window_closed_hint }}
        </p>

        <div class="lg:grid lg:grid-cols-[minmax(0,1fr)_24rem] lg:gap-8">
            <div class="min-w-0">
                <div
                    class="sticky top-0 z-10 -mx-4 space-y-2 border-b border-border bg-background px-4 py-2 sm:-mx-6 sm:px-6 lg:mx-0 lg:px-0"
                >
                    <div class="relative">
                        <Search
                            class="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-muted-foreground"
                            aria-hidden="true"
                        />
                        <Input
                            v-model="query"
                            type="search"
                            class="pl-9"
                            :placeholder="labels.search_placeholder"
                            :aria-label="labels.search_placeholder"
                        />
                    </div>
                    <div
                        class="flex flex-wrap items-center gap-1.5"
                        role="group"
                        :aria-label="labels.filter_group"
                        data-test="menu-filters"
                    >
                        <button
                            type="button"
                            :class="chipClass(filters.mine)"
                            :aria-pressed="filters.mine"
                            @click="filters.mine = !filters.mine"
                        >
                            {{ labels.filter_mine }}
                        </button>
                        <button
                            v-for="chip in priceChips"
                            :key="chip.cents"
                            type="button"
                            :class="
                                chipClass(filters.maxPriceCents === chip.cents)
                            "
                            :aria-pressed="filters.maxPriceCents === chip.cents"
                            @click="togglePrice(chip.cents)"
                        >
                            {{ chip.label }}
                        </button>
                        <span
                            class="ml-auto text-sm text-muted-foreground tabular-nums"
                            data-test="filter-count"
                        >
                            {{
                                labels.filter_count
                                    .replace(':shown', String(visibleCount))
                                    .replace(':total', String(totalDishes))
                            }}
                        </span>
                        <Button
                            v-if="narrowed"
                            variant="ghost"
                            size="sm"
                            @click="resetFilters"
                        >
                            {{ labels.filter_reset }}
                        </Button>
                    </div>
                    <nav
                        v-if="menu.grouped && visibleGroups.length > 0"
                        :aria-label="labels.groups_nav"
                        class="-mx-1 overflow-x-auto px-1 pb-1"
                    >
                        <ul class="flex w-max gap-1.5">
                            <li v-for="group in visibleGroups" :key="group.id">
                                <a
                                    :href="`#${anchorId(group.id)}`"
                                    class="inline-flex min-h-9 items-center gap-1.5 rounded-md border border-border px-3 text-sm whitespace-nowrap text-muted-foreground outline-none hover:bg-accent hover:text-foreground focus-visible:ring-2 focus-visible:ring-ring pointer-coarse:min-h-11"
                                >
                                    {{ group.label }}
                                    <span
                                        class="font-mono text-xs tabular-nums"
                                        >{{ group.items.length }}</span
                                    >
                                </a>
                            </li>
                        </ul>
                    </nav>
                </div>

                <div
                    v-if="visibleCount === 0"
                    class="mt-4 rounded-md border border-dashed border-border p-6 text-center"
                    data-test="no-results"
                >
                    <p class="text-sm text-muted-foreground">
                        {{
                            !narrowed
                                ? labels.menu_empty
                                : filtersActive
                                  ? labels.filter_no_results
                                  : labels.no_results
                        }}
                    </p>
                    <Button
                        v-if="narrowed"
                        variant="outline"
                        size="sm"
                        class="mt-3"
                        @click="resetFilters"
                    >
                        {{
                            filtersActive
                                ? labels.filter_reset
                                : labels.clear_search
                        }}
                    </Button>
                </div>

                <div v-else class="mt-2 xl:columns-2 xl:gap-x-10">
                    <section
                        v-for="group in visibleGroups"
                        :key="group.id"
                        class="mb-6 break-inside-avoid"
                        :aria-labelledby="
                            menu.grouped ? anchorId(group.id) : undefined
                        "
                    >
                        <h3
                            v-if="menu.grouped"
                            :id="anchorId(group.id)"
                            class="scroll-mt-28 pt-2 pb-1 text-sm font-semibold"
                        >
                            {{ group.label }}
                        </h3>
                        <ul
                            :class="[
                                'divide-y divide-border border-y border-border',
                                foodOrder.isOpen ? '' : 'text-muted-foreground',
                            ]"
                        >
                            <li
                                v-for="item in group.items"
                                :key="item.key"
                                class="flex items-center gap-2 py-1"
                            >
                                <button
                                    v-if="item.imageUrl"
                                    type="button"
                                    class="size-14 shrink-0 overflow-hidden rounded-md border border-border outline-none focus-visible:ring-2 focus-visible:ring-ring"
                                    :aria-label="
                                        labels.dish_photo_open.replace(
                                            ':dish',
                                            item.label,
                                        )
                                    "
                                    data-test="dish-photo"
                                    @click="
                                        photo = {
                                            label: item.label,
                                            url: item.imageUrl,
                                        }
                                    "
                                >
                                    <img
                                        :src="item.imageUrl"
                                        alt=""
                                        width="56"
                                        height="56"
                                        loading="lazy"
                                        decoding="async"
                                        class="size-14 object-cover"
                                    />
                                </button>
                                <div class="min-w-0 flex-1">
                                    <p class="text-sm break-words">
                                        <span
                                            v-if="countFor(item.key) > 0"
                                            class="mr-1.5 font-mono font-medium tabular-nums"
                                            >{{ countFor(item.key) }}×</span
                                        >
                                        {{ item.label }}
                                    </p>
                                    <template v-if="item.description">
                                        <p
                                            :id="`dish-desc-${foodOrder.id}-${item.key}`"
                                            :class="[
                                                'text-sm break-words text-muted-foreground',
                                                expanded.has(item.key)
                                                    ? ''
                                                    : 'line-clamp-2',
                                            ]"
                                            data-test="dish-description"
                                        >
                                            {{ item.description }}
                                        </p>
                                        <button
                                            v-if="
                                                item.description.length >
                                                LONG_DESCRIPTION
                                            "
                                            type="button"
                                            class="rounded-sm text-sm text-muted-foreground underline underline-offset-2 outline-none hover:text-foreground focus-visible:ring-2 focus-visible:ring-ring"
                                            :aria-expanded="
                                                expanded.has(item.key)
                                            "
                                            :aria-controls="`dish-desc-${foodOrder.id}-${item.key}`"
                                            @click="toggleExpanded(item.key)"
                                        >
                                            {{
                                                expanded.has(item.key)
                                                    ? labels.dish_less
                                                    : labels.dish_more
                                            }}
                                        </button>
                                    </template>
                                </div>
                                <span
                                    class="shrink-0 text-right font-mono text-sm tabular-nums"
                                >
                                    {{ formatEuro(item.priceCents) }}
                                </span>
                                <span class="size-11 shrink-0">
                                    <Button
                                        v-if="countFor(item.key) > 0"
                                        variant="ghost"
                                        size="icon"
                                        class="size-11 text-muted-foreground hover:bg-destructive/10 hover:text-destructive focus-visible:text-destructive"
                                        :disabled="!editable"
                                        :aria-label="
                                            labels.remove_aria.replace(
                                                ':dish',
                                                item.label,
                                            )
                                        "
                                        @click="removeOne(item.key)"
                                    >
                                        <Minus aria-hidden="true" />
                                    </Button>
                                </span>
                                <Button
                                    variant="outline"
                                    size="icon"
                                    class="size-11 shrink-0"
                                    :disabled="
                                        !editable || pending.has(item.key)
                                    "
                                    :aria-busy="pending.has(item.key)"
                                    :aria-label="
                                        labels.add_aria.replace(
                                            ':dish',
                                            item.label,
                                        )
                                    "
                                    @click="add(item.key)"
                                >
                                    <LoaderCircle
                                        v-if="pending.has(item.key)"
                                        class="animate-spin motion-reduce:animate-none"
                                        aria-hidden="true"
                                    />
                                    <Check
                                        v-else-if="justAdded.has(item.key)"
                                        class="text-foreground"
                                        aria-hidden="true"
                                    />
                                    <Plus v-else aria-hidden="true" />
                                </Button>
                            </li>
                        </ul>
                    </section>
                </div>
            </div>

            <aside
                v-if="isDesktop"
                :aria-label="labels.my_order"
                data-test="receipt-desktop"
            >
                <div
                    class="sticky top-4 flex max-h-[calc(100dvh-2rem)] flex-col rounded-md border border-border bg-card p-4"
                >
                    <div
                        class="mb-3 flex shrink-0 items-baseline justify-between gap-2"
                    >
                        <h3 class="text-sm font-semibold">
                            {{ labels.my_order }}
                        </h3>
                        <span class="text-sm text-muted-foreground">{{
                            countLabel()
                        }}</span>
                    </div>
                    <FoodReceipt
                        v-model:note="note"
                        :food-order="foodOrder"
                        :labels="labels"
                        :editable="editable"
                        :primary-payment="primaryPayment"
                        :highlight-key="highlightKey"
                        :dish-name="dishName"
                        @remove="removeItem"
                    />
                </div>
            </aside>
        </div>

        <div
            v-if="!isDesktop"
            class="sticky bottom-0 z-20 -mx-4 flex items-center justify-between gap-3 border-t border-border bg-background px-4 py-2 sm:-mx-6 sm:px-6"
            data-test="cart-bar"
        >
            <p
                :class="[
                    'min-w-0 rounded-sm px-1 text-sm',
                    barBumped ? 'bg-accent motion-reduce:bg-transparent' : '',
                ]"
                data-test="cart-summary"
            >
                <span class="font-medium">{{ countLabel() }}</span>
                <span
                    v-if="itemCount > 0"
                    class="ml-2 font-mono tabular-nums"
                    >{{ formatEuro(totalCents) }}</span
                >
            </p>
            <Button variant="outline" @click="sheetOpen = true">
                {{ labels.cart_bar_open }}
            </Button>
        </div>

        <Dialog v-model:open="photoOpen">
            <DialogContent class="sm:max-w-xl">
                <DialogHeader>
                    <DialogTitle>{{ photo?.label }}</DialogTitle>
                    <DialogDescription>
                        {{ labels.dish_photo_hint }}
                    </DialogDescription>
                </DialogHeader>
                <img
                    v-if="photo"
                    :src="photo.url"
                    alt=""
                    width="800"
                    height="600"
                    decoding="async"
                    class="max-h-[70vh] w-full rounded-md object-contain"
                />
            </DialogContent>
        </Dialog>

        <Sheet v-if="!isDesktop" v-model:open="sheetOpen">
            <SheetContent
                side="bottom"
                class="max-h-[85vh] overflow-y-auto p-4"
            >
                <SheetHeader class="p-0">
                    <SheetTitle>{{ labels.my_order }}</SheetTitle>
                    <SheetDescription>{{ foodOrder.title }}</SheetDescription>
                </SheetHeader>
                <FoodReceipt
                    v-model:note="note"
                    :food-order="foodOrder"
                    :labels="labels"
                    :editable="editable"
                    :primary-payment="primaryPayment"
                    :highlight-key="highlightKey"
                    :dish-name="dishName"
                    @remove="removeItem"
                />
            </SheetContent>
        </Sheet>
    </section>
</template>
