<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { Minus, Plus, Search } from '@lucide/vue';
import { useMediaQuery } from '@vueuse/core';
import { computed, onBeforeUnmount, ref } from 'vue';
import FoodReceipt from '@/components/catering/FoodReceipt.vue';
import LiveIndicator from '@/components/LiveIndicator.vue';
import { Button } from '@/components/ui/button';
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
const visibleGroups = computed(() =>
    filterMenuGroups(menu.value.groups, query.value),
);
const visibleCount = computed(() =>
    visibleGroups.value.reduce((sum, group) => sum + group.items.length, 0),
);

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

    const full =
        props.foodOrder.menu.find((option) => option.key === optionKey)?.name ??
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

function add(optionKey: string): void {
    router.post(
        storeItem.url({ foodOrder: props.foodOrder.id }),
        { option_key: optionKey, note: note.value || undefined },
        {
            preserveScroll: true,
            onSuccess: (page) => {
                if (!wasRefused(page.flash)) {
                    note.value = '';
                    highlight(optionKey);
                }
            },
        },
    );
}

function removeItem(itemId: number): void {
    router.delete(destroyItem.url({ foodOrderItem: itemId }), {
        preserveScroll: true,
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
        <div
            class="flex flex-wrap items-center justify-between gap-x-4 gap-y-1"
        >
            <div class="min-w-0">
                <h2
                    :id="`round-title-${foodOrder.id}`"
                    class="text-xl font-semibold tracking-tight"
                >
                    {{ foodOrder.title }}
                </h2>
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
                            query.trim() === ''
                                ? labels.menu_empty
                                : labels.no_results
                        }}
                    </p>
                    <Button
                        v-if="query.trim() !== ''"
                        variant="outline"
                        size="sm"
                        class="mt-3"
                        @click="query = ''"
                    >
                        {{ labels.clear_search }}
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
                                <p class="min-w-0 flex-1 text-sm break-words">
                                    <span
                                        v-if="countFor(item.key) > 0"
                                        class="mr-1.5 font-mono font-medium tabular-nums"
                                        >{{ countFor(item.key) }}×</span
                                    >
                                    {{ item.label }}
                                </p>
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
                                    :disabled="!editable"
                                    :aria-label="
                                        labels.add_aria.replace(
                                            ':dish',
                                            item.label,
                                        )
                                    "
                                    @click="add(item.key)"
                                >
                                    <Plus aria-hidden="true" />
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
            <p class="min-w-0 text-sm" aria-live="polite">
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
