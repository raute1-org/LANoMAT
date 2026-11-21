<script setup lang="ts">
import { Minus } from '@lucide/vue';
import { computed } from 'vue';
import FoodPaymentState from '@/components/catering/FoodPaymentState.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { formatEuro } from '@/lib/formatEuro';
import { aggregateItems } from '@/lib/receipt';
import type { FoodOrderDto } from '@/types';

const props = defineProps<{
    foodOrder: FoodOrderDto;
    labels: Record<string, string>;
    /** The round accepts changes (open window and a confirmed registration). */
    editable: boolean;
    /** Show the amber pay button for this receipt. */
    primaryPayment: boolean;
    note: string;
    highlightKey: string | null;
    dishName: (optionKey: string | null) => string;
}>();

const emit = defineEmits<{
    'update:note': [value: string];
    remove: [itemId: number];
}>();

const lines = computed(() => aggregateItems(props.foodOrder.myItems));

const totalCents = computed(() =>
    lines.value.reduce((sum, line) => sum + line.totalCents, 0),
);

const hasPayableItems = computed(() =>
    props.foodOrder.myItems.some((item) => !item.included),
);

function lineKey(line: { optionKey: string | null; included: boolean }) {
    return `${line.optionKey ?? ''}|${line.included ? 1 : 0}`;
}
</script>

<template>
    <div class="flex min-h-0 flex-1 flex-col gap-4" data-test="food-receipt">
        <div class="min-h-0 flex-1 overflow-y-auto">
            <p
                v-if="lines.length === 0"
                class="text-sm text-muted-foreground"
                data-test="receipt-empty"
            >
                {{ labels.receipt_empty }}
            </p>

            <ul v-else class="space-y-3">
                <li
                    v-for="line in lines"
                    :key="lineKey(line)"
                    :class="[
                        '-mx-2 rounded-sm px-2 py-0.5',
                        highlightKey === line.optionKey
                            ? 'bg-accent motion-reduce:bg-transparent'
                            : '',
                    ]"
                >
                    <div class="flex items-center gap-2">
                        <div class="flex min-w-0 flex-1 items-baseline gap-2">
                            <span
                                class="shrink-0 font-mono text-sm font-medium tabular-nums"
                            >
                                {{ line.count }}×
                            </span>
                            <span class="min-w-0 text-sm break-words">
                                {{ dishName(line.optionKey) }}
                            </span>
                            <span
                                class="min-w-3 flex-1 self-end border-b border-dotted border-muted-foreground/50"
                                aria-hidden="true"
                            />
                            <span
                                v-if="line.included"
                                class="shrink-0 text-sm text-muted-foreground"
                            >
                                {{ labels.item_included }}
                            </span>
                            <span
                                v-else
                                class="shrink-0 font-mono text-sm tabular-nums"
                            >
                                {{ formatEuro(line.totalCents) }}
                            </span>
                        </div>
                        <Button
                            variant="ghost"
                            size="icon-sm"
                            class="shrink-0 text-muted-foreground hover:bg-destructive/10 hover:text-destructive focus-visible:text-destructive"
                            :disabled="!editable"
                            :aria-label="
                                labels.remove_aria.replace(
                                    ':dish',
                                    dishName(line.optionKey),
                                )
                            "
                            @click="emit('remove', line.newestItemId)"
                        >
                            <Minus aria-hidden="true" />
                        </Button>
                    </div>
                    <ul
                        v-if="line.notes.length > 0"
                        class="mt-0.5 ml-7 space-y-0.5 text-xs text-muted-foreground"
                    >
                        <li v-for="(text, index) in line.notes" :key="index">
                            {{ text }}
                        </li>
                    </ul>
                </li>
            </ul>
        </div>

        <div class="shrink-0 space-y-4">
            <div
                v-if="hasPayableItems"
                class="flex items-baseline justify-between gap-3 border-t border-border pt-3"
            >
                <span class="text-sm font-semibold">{{ labels.my_total }}</span>
                <span
                    class="font-mono text-base font-semibold tabular-nums"
                    data-test="receipt-total"
                >
                    {{ formatEuro(totalCents) }}
                </span>
            </div>

            <div v-if="editable" class="space-y-1.5">
                <label
                    class="text-sm font-medium"
                    :for="`note-${foodOrder.id}`"
                >
                    {{ labels.note_label }}
                </label>
                <Input
                    :id="`note-${foodOrder.id}`"
                    maxlength="200"
                    :placeholder="labels.note_placeholder"
                    :model-value="note"
                    @update:model-value="
                        (value) => emit('update:note', String(value))
                    "
                />
            </div>

            <div v-if="foodOrder.myPayment" class="border-t border-border pt-3">
                <FoodPaymentState
                    :payment="foodOrder.myPayment"
                    :primary="primaryPayment"
                    :labels="labels"
                />
            </div>
        </div>
    </div>
</template>
