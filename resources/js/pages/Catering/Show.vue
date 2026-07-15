<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import {
    destroy as destroyItem,
    store as storeItem,
} from '@/routes/catering/items';
import type { FoodOrderDto } from '@/types';

defineProps<{
    event: { name: string; slug: string };
    orders: FoodOrderDto[];
    labels: Record<string, string>;
}>();

const noteByOrder = new Map<number, string>();

function noteFor(orderId: number): string {
    return noteByOrder.get(orderId) ?? '';
}

function setNoteFor(orderId: number, value: string): void {
    noteByOrder.set(orderId, value);
}

function order(order: FoodOrderDto, optionKey: string): void {
    const form = useForm({
        option_key: optionKey,
        note: noteFor(order.id) || undefined,
    });

    form.post(storeItem.url({ foodOrder: order.id }), {
        preserveScroll: true,
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
    return new Date(iso).toLocaleString('de-DE');
}
</script>

<template>
    <Head :title="`${labels.title} — ${event.name}`" />

    <main class="mx-auto max-w-3xl px-4 py-12">
        <h1 class="text-3xl font-bold tracking-tight">
            {{ labels.title }} — {{ event.name }}
        </h1>

        <p
            v-if="orders.length === 0"
            class="mt-6 text-sm text-muted-foreground"
        >
            {{ labels.empty }}
        </p>

        <div v-else class="mt-8 space-y-8">
            <Card v-for="foodOrder in orders" :key="foodOrder.id">
                <CardHeader>
                    <div class="flex items-center justify-between gap-4">
                        <CardTitle>{{ foodOrder.title }}</CardTitle>
                        <Badge
                            :variant="foodOrder.isOpen ? 'default' : 'outline'"
                        >
                            {{
                                foodOrder.isOpen
                                    ? labels.window_open
                                    : labels.window_closed
                            }}
                        </Badge>
                    </div>
                    <CardDescription
                        v-if="foodOrder.opensAt || foodOrder.closesAt"
                    >
                        <span v-if="foodOrder.opensAt">
                            {{ labels.opens_at }}:
                            {{ formatDateTime(foodOrder.opensAt) }}
                        </span>
                        <span v-if="foodOrder.closesAt">
                            · {{ labels.closes_at }}:
                            {{ formatDateTime(foodOrder.closesAt) }}
                        </span>
                    </CardDescription>
                </CardHeader>

                <CardContent class="space-y-6">
                    <section>
                        <h2 class="text-sm font-semibold text-muted-foreground">
                            {{ labels.menu }}
                        </h2>
                        <ul
                            class="mt-3 divide-y divide-border rounded-lg border border-border"
                        >
                            <li
                                v-for="option in foodOrder.menu"
                                :key="option.key"
                                class="flex items-center justify-between gap-4 px-4 py-3"
                            >
                                <div>
                                    <p class="font-medium">{{ option.name }}</p>
                                    <p class="text-sm text-muted-foreground">
                                        {{ formatEuro(option.priceCents) }}
                                    </p>
                                </div>
                                <Button
                                    size="sm"
                                    :disabled="!foodOrder.isOpen"
                                    @click="order(foodOrder, option.key)"
                                >
                                    {{ labels.order }}
                                </Button>
                            </li>
                        </ul>
                        <Input
                            v-if="foodOrder.isOpen"
                            class="mt-3"
                            :placeholder="labels.note_placeholder"
                            :model-value="noteFor(foodOrder.id)"
                            @update:model-value="
                                (value) =>
                                    setNoteFor(foodOrder.id, String(value))
                            "
                        />
                    </section>

                    <section>
                        <h2 class="text-sm font-semibold text-muted-foreground">
                            {{ labels.my_order }}
                        </h2>

                        <p
                            v-if="foodOrder.myItems.length === 0"
                            class="mt-2 text-sm text-muted-foreground"
                        >
                            {{ labels.my_order_empty }}
                        </p>

                        <ul
                            v-else
                            class="mt-3 divide-y divide-border rounded-lg border border-border"
                        >
                            <li
                                v-for="item in foodOrder.myItems"
                                :key="item.id"
                                class="flex items-center justify-between gap-4 px-4 py-3"
                            >
                                <div>
                                    <p class="font-medium">
                                        {{ item.optionKey }}
                                    </p>
                                    <p
                                        v-if="item.note"
                                        class="text-sm text-muted-foreground"
                                    >
                                        {{ item.note }}
                                    </p>
                                    <p class="text-sm text-muted-foreground">
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
                            class="mt-3 text-sm font-medium"
                        >
                            {{ labels.my_total }}:
                            {{ formatEuro(foodOrder.myTotalCents) }}
                        </p>
                    </section>
                </CardContent>
            </Card>
        </div>
    </main>
</template>
