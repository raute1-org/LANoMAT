<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { reactive } from 'vue';
import LiveIndicator from '@/components/LiveIndicator.vue';
import PageContainer from '@/components/PageContainer.vue';
import Panel from '@/components/Panel.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { formatInstant } from '@/lib/datetime';
import {
    destroy as destroyItem,
    store as storeItem,
} from '@/routes/catering/items';
import type { FoodOrderDto } from '@/types';

defineProps<{
    event: { name: string; slug: string };
    orders: FoodOrderDto[];
    labels: Record<string, string>;
    registration: { required: boolean; url: string };
}>();

// Reactive, so clearing a note after a successful order also clears the input.
const notes = reactive<Record<number, string>>({});

function noteFor(orderId: number): string {
    return notes[orderId] ?? '';
}

function setNoteFor(orderId: number, value: string): void {
    notes[orderId] = value;
}

// The controller answers a refused order with a redirect and an error toast,
// which Inertia still reports as success. Keep the note in that case.
function wasRefused(flash: Record<string, unknown> | undefined): boolean {
    const toast = flash?.toast as { type?: string } | undefined;

    return toast?.type === 'error';
}

function order(order: FoodOrderDto, optionKey: string): void {
    const form = useForm({
        option_key: optionKey,
        note: noteFor(order.id) || undefined,
    });

    form.post(storeItem.url({ foodOrder: order.id }), {
        preserveScroll: true,
        onSuccess: (page) => {
            if (!wasRefused(page.flash)) {
                delete notes[order.id];
            }
        },
    });
}

function cancel(item: { id: number }): void {
    const form = useForm({});

    form.delete(destroyItem.url({ foodOrderItem: item.id }), {
        preserveScroll: true,
    });
}

function formatEuro(cents: number): string {
    return new Intl.NumberFormat('de-DE', {
        style: 'currency',
        currency: 'EUR',
    }).format(cents / 100);
}

function formatDateTime(iso: string): string {
    return formatInstant(iso, { dateStyle: 'short', timeStyle: 'short' });
}

function menuOptionName(
    foodOrder: FoodOrderDto,
    optionKey: string | null,
): string {
    if (optionKey === null) {
        return '';
    }

    const option = foodOrder.menu.find(
        (candidate) => candidate.key === optionKey,
    );

    return option?.name ?? optionKey;
}
</script>

<template>
    <Head :title="`${labels.title} — ${event.name}`" />

    <PageContainer width="default" :title="`${labels.title} — ${event.name}`">
        <div
            v-if="registration.required"
            class="mt-6 flex flex-col gap-3 rounded-md border bg-card p-4 sm:flex-row sm:items-center sm:justify-between"
        >
            <p class="text-sm">{{ labels.registration_required }}</p>
            <Button as-child size="sm" class="shrink-0">
                <a :href="registration.url">{{ labels.register_link }}</a>
            </Button>
        </div>

        <div
            v-if="orders.length === 0"
            class="mt-6 rounded-md border border-dashed border-border p-6 text-center"
        >
            <p class="text-sm text-muted-foreground">
                {{ labels.empty }}
            </p>
        </div>

        <div v-else class="mt-6 space-y-4">
            <div
                v-for="foodOrder in orders"
                :key="foodOrder.id"
                class="space-y-4"
            >
                <div
                    class="flex flex-wrap items-center justify-between gap-x-4 gap-y-1"
                >
                    <div class="min-w-0">
                        <h2 class="text-base font-semibold">
                            {{ foodOrder.title }}
                        </h2>
                        <p
                            v-if="foodOrder.opensAt || foodOrder.closesAt"
                            class="text-sm text-muted-foreground"
                        >
                            <span v-if="foodOrder.opensAt">
                                {{ labels.opens_at }}:
                                <span class="font-mono tabular-nums">{{
                                    formatDateTime(foodOrder.opensAt)
                                }}</span>
                            </span>
                            <span v-if="foodOrder.closesAt">
                                · {{ labels.closes_at }}:
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
                            foodOrder.isOpen
                                ? labels.window_open
                                : labels.window_closed
                        "
                    />
                </div>

                <Panel :title="labels.menu" :level="3">
                    <ul class="divide-y divide-border border-t border-border">
                        <li
                            v-for="option in foodOrder.menu"
                            :key="option.key"
                            class="flex items-center justify-between gap-4 px-4 py-2.5"
                        >
                            <div class="min-w-0">
                                <p class="font-medium">{{ option.name }}</p>
                                <p
                                    class="font-mono text-sm text-muted-foreground tabular-nums"
                                >
                                    {{ formatEuro(option.priceCents) }}
                                </p>
                            </div>
                            <Button
                                size="sm"
                                variant="outline"
                                :disabled="
                                    !foodOrder.isOpen || registration.required
                                "
                                @click="order(foodOrder, option.key)"
                            >
                                {{ labels.order }}
                            </Button>
                        </li>
                    </ul>
                    <div
                        v-if="foodOrder.isOpen && !registration.required"
                        class="border-t border-border p-4"
                    >
                        <Input
                            maxlength="200"
                            :placeholder="labels.note_placeholder"
                            :aria-label="labels.note_placeholder"
                            :model-value="noteFor(foodOrder.id)"
                            @update:model-value="
                                (value) =>
                                    setNoteFor(foodOrder.id, String(value))
                            "
                        />
                    </div>
                </Panel>

                <Panel :title="labels.my_order" :level="3">
                    <p
                        v-if="foodOrder.myItems.length === 0"
                        class="border-t border-border px-4 py-3 text-sm text-muted-foreground"
                    >
                        {{ labels.my_order_empty }}
                    </p>

                    <ul
                        v-else
                        class="divide-y divide-border border-t border-border"
                    >
                        <li
                            v-for="item in foodOrder.myItems"
                            :key="item.id"
                            class="flex items-center justify-between gap-4 px-4 py-2.5"
                        >
                            <div class="min-w-0">
                                <p class="font-medium">
                                    {{
                                        menuOptionName(
                                            foodOrder,
                                            item.optionKey,
                                        )
                                    }}
                                </p>
                                <p
                                    v-if="item.note"
                                    class="text-sm text-muted-foreground"
                                >
                                    {{ item.note }}
                                </p>
                                <p
                                    class="font-mono text-sm text-muted-foreground tabular-nums"
                                >
                                    {{ formatEuro(item.priceCents) }}
                                </p>
                            </div>
                            <Button
                                size="sm"
                                variant="destructive"
                                :disabled="!foodOrder.isOpen"
                                @click="cancel(item)"
                            >
                                {{ labels.cancel }}
                            </Button>
                        </li>
                    </ul>

                    <p
                        v-if="foodOrder.myItems.length > 0"
                        class="border-t border-border px-4 py-2.5 text-sm font-medium"
                    >
                        {{ labels.my_total }}:
                        <span class="font-mono tabular-nums">{{
                            formatEuro(foodOrder.myTotalCents)
                        }}</span>
                    </p>
                </Panel>
            </div>
        </div>
    </PageContainer>
</template>
