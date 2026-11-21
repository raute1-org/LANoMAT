<script setup lang="ts">
import CopyText from '@/components/CopyText.vue';
import { Button } from '@/components/ui/button';
import { formatEuro } from '@/lib/formatEuro';
import type { RegistrationPayment } from '@/types';

withDefaults(
    defineProps<{
        payment: RegistrationPayment;
        labels: Record<string, string>;
        /** The pay button is the amber primary only while it is the screen's single main action. */
        primary?: boolean;
    }>(),
    { primary: true },
);
</script>

<template>
    <section
        id="payment"
        class="scroll-mt-20 space-y-3 rounded-md border border-border bg-card p-4"
        data-test="payment-block"
    >
        <div class="flex items-baseline justify-between gap-3">
            <span class="text-sm text-muted-foreground">{{
                labels.pay_amount
            }}</span>
            <span class="font-mono text-lg text-foreground">{{
                formatEuro(payment.amountCents)
            }}</span>
        </div>

        <p
            v-if="payment.paid"
            class="font-mono text-sm text-ok"
            data-test="payment-paid"
        >
            {{ labels.paid }}
        </p>
        <template v-else>
            <p class="text-sm text-warn">{{ labels.unpaid }}</p>

            <template v-if="payment.url">
                <Button
                    as-child
                    :variant="primary ? 'default' : 'outline'"
                    data-test="pay-link"
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
                <p
                    v-if="!payment.linkAppendsAmount"
                    class="text-sm text-muted-foreground"
                    data-test="pay-amount-hint"
                >
                    {{
                        labels.pay_amount_hint.replace(
                            ':amount',
                            formatEuro(payment.amountCents),
                        )
                    }}
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
                    <CopyText
                        :text="payment.reference"
                        :label="labels.copy"
                        :aria-label="labels.copy_reference"
                        :copied-label="labels.copied"
                        :failed-label="labels.copy_failed"
                    />
                    <p class="text-xs text-muted-foreground">
                        {{ labels.pay_reference_hint }}
                    </p>
                </div>
            </template>
            <p v-else class="text-sm text-foreground" data-test="pay-at-orga">
                {{ labels.pay_at_orga }}
            </p>

            <p
                v-if="payment.note"
                class="text-sm text-muted-foreground"
                data-test="pay-note"
            >
                {{ payment.note }}
            </p>
        </template>
    </section>
</template>
