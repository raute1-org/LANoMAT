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
            <Button
                v-if="payment.url"
                as-child
                size="sm"
                :variant="primary ? 'default' : 'outline'"
            >
                <a
                    :href="payment.url"
                    target="_blank"
                    rel="noopener noreferrer"
                    >{{ labels.pay_button }}</a
                >
            </Button>
            <span v-else class="text-sm text-muted-foreground">{{
                labels.pay_no_link
            }}</span>
            <CopyText
                v-if="payment.reference"
                :text="payment.reference"
                :label="labels.pay_copy_reference"
            />
        </template>
    </div>
</template>
