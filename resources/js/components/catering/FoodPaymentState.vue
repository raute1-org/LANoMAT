<script setup lang="ts">
import { computed } from 'vue';
import CopyText from '@/components/CopyText.vue';
import { Button } from '@/components/ui/button';
import { formatEuro } from '@/lib/formatEuro';
import type { MyFoodPayment } from '@/types/catering';

const props = defineProps<{
    payment: MyFoodPayment;
    /** True once the round is closed: the pay button becomes the amber primary action. */
    primary: boolean;
    labels: Record<string, string>;
}>();

const amount = computed(() => formatEuro(props.payment.dueCents));

function fill(template: string): string {
    return template.replace(':amount', amount.value);
}
</script>

<template>
    <div
        class="flex flex-wrap items-center gap-x-3 gap-y-2"
        data-test="food-payment"
    >
        <p
            v-if="payment.method === 'included'"
            class="text-sm text-muted-foreground"
        >
            {{ labels.pay_included }}
        </p>
        <p
            v-else-if="payment.dueCents === 0"
            class="text-sm text-muted-foreground"
        >
            {{ labels.pay_nothing_due }}
        </p>
        <p v-else-if="payment.method === 'cash'" class="text-sm font-medium">
            {{ fill(labels.pay_cash) }}
        </p>
        <template v-else>
            <p class="text-sm font-medium">{{ fill(labels.pay_due) }}</p>
            <template v-if="payment.url">
                <Button
                    as-child
                    size="sm"
                    :variant="primary ? 'default' : 'outline'"
                >
                    <a
                        :href="payment.url"
                        target="_blank"
                        rel="noopener noreferrer"
                    >
                        {{ labels.pay_with_paypal }}
                        <span class="sr-only">{{ labels.new_tab }}</span>
                    </a>
                </Button>
                <div v-if="payment.reference" class="w-full space-y-1">
                    <p
                        class="font-mono text-sm break-words text-foreground"
                        data-test="food-pay-reference"
                    >
                        {{ payment.reference }}
                    </p>
                    <CopyText
                        :text="payment.reference"
                        :label="labels.pay_copy"
                        :aria-label="labels.copy_reference"
                        :copied-label="labels.pay_copied"
                        :failed-label="labels.pay_copy_failed"
                    />
                    <p class="text-xs text-muted-foreground">
                        {{ labels.pay_reference_hint }}
                    </p>
                </div>
            </template>
            <span v-else class="text-sm text-muted-foreground">{{
                labels.pay_at_orga
            }}</span>
        </template>
    </div>
</template>
