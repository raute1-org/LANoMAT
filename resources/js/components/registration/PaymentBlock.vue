<script setup lang="ts">
import CopyText from '@/components/CopyText.vue';
import { Button } from '@/components/ui/button';
import { formatEuro } from '@/lib/formatEuro';
import type { RegistrationPayment } from '@/types';

defineProps<{
    payment: RegistrationPayment;
    labels: Record<string, string>;
}>();
</script>

<template>
    <div
        class="space-y-3 rounded-md border border-border bg-card p-4"
        data-test="payment-block"
    >
        <p
            v-if="payment.paid"
            class="font-mono text-sm text-ok"
            data-test="payment-paid"
        >
            {{ labels.paid }}
        </p>
        <template v-else>
            <div class="flex items-baseline justify-between gap-3">
                <span class="text-sm text-muted-foreground">{{
                    labels.pay_amount
                }}</span>
                <span class="font-mono text-lg text-foreground">{{
                    formatEuro(payment.amountCents)
                }}</span>
            </div>
            <p class="text-sm text-warn">{{ labels.unpaid }}</p>

            <Button v-if="payment.url" as-child data-test="pay-link">
                <a :href="payment.url" target="_blank" rel="noopener">{{
                    labels.pay_with_paypal
                }}</a>
            </Button>
            <p v-else class="text-sm text-foreground" data-test="pay-at-orga">
                {{ labels.pay_at_orga }}
            </p>

            <div class="space-y-1">
                <p class="text-sm text-muted-foreground">
                    {{ labels.pay_reference }}
                </p>
                <p
                    class="font-mono text-sm break-words text-foreground"
                    data-test="pay-reference"
                >
                    {{ payment.reference }}
                </p>
                <CopyText :text="payment.reference" :label="labels.copy" />
                <p class="text-xs text-muted-foreground">
                    {{ labels.pay_reference_hint }}
                </p>
            </div>

            <p
                v-if="payment.note"
                class="text-sm text-muted-foreground"
                data-test="pay-note"
            >
                {{ payment.note }}
            </p>
        </template>
    </div>
</template>
