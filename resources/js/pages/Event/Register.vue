<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import LiveIndicator from '@/components/LiveIndicator.vue';
import PageContainer from '@/components/PageContainer.vue';
import { Button } from '@/components/ui/button';
import { show as eventsShow } from '@/routes/events';
import {
    destroy as destroyRegistration,
    store as storeRegistration,
} from '@/routes/events/register';
import type { RegistrationDetails } from '@/types';

const props = defineProps<{
    event: { name: string; slug: string; status: string };
    tickets: string[];
    ticketLabels: Record<string, string>;
    registration: RegistrationDetails | null;
    labels: Record<string, string>;
    statusLabels: Record<string, string>;
}>();

const selected = ref(props.tickets[0] ?? 'standard');

const registerForm = useForm({ ticket_type: selected.value });
const cancelForm = useForm({});

const registrationState = computed<'checked_in' | 'pending' | 'confirmed'>(
    () => {
        if (!props.registration) {
            return 'pending';
        }

        if (props.registration.checkedIn) {
            return 'checked_in';
        }

        return props.registration.status === 'pending'
            ? 'pending'
            : 'confirmed';
    },
);

function submit() {
    registerForm.ticket_type = selected.value;
    registerForm.post(storeRegistration.url({ event: props.event.slug }), {
        preserveScroll: true,
    });
}

function cancel() {
    if (!confirm(props.labels.cancel_confirm)) {
        return;
    }

    cancelForm.delete(destroyRegistration.url({ event: props.event.slug }), {
        preserveScroll: true,
    });
}
</script>

<template>
    <Head :title="labels.title" />

    <PageContainer
        width="narrow"
        :title="event.name"
        :description="labels.title"
        :back="{ href: eventsShow.url(event.slug), label: 'Zurück zum Event' }"
    >
        <section v-if="registration" class="mt-8 space-y-6">
            <div class="flex items-center justify-between">
                <h2 class="text-xl font-semibold text-foreground">
                    {{ labels.my_registration }}
                </h2>
                <LiveIndicator
                    v-if="registrationState === 'checked_in'"
                    variant="ok"
                    :label="labels.checked_in"
                    :pulse="false"
                />
                <span
                    v-else-if="registrationState === 'pending'"
                    class="font-mono text-xs tracking-wide text-warn uppercase"
                >
                    {{ statusLabels.pending }}
                </span>
            </div>

            <div class="rounded-lg border border-border bg-card p-4">
                <!-- eslint-disable-next-line vue/no-v-html -- server-rendered QR SVG, no user input -->
                <div class="mx-auto w-64" v-html="registration.qrSvg" />
                <p class="mt-3 text-center text-sm text-muted-foreground">
                    {{ labels.qr_hint }}
                </p>
            </div>

            <dl class="grid grid-cols-2 gap-y-2 text-sm">
                <dt class="text-muted-foreground">{{ labels.ticket }}</dt>
                <dd class="text-foreground">
                    {{ registration.ticketLabel }}
                </dd>
                <dt class="text-muted-foreground">{{ labels.status }}</dt>
                <dd class="text-foreground">
                    {{ registration.paid ? labels.paid : labels.unpaid }}
                </dd>
            </dl>

            <Button
                variant="destructive"
                :disabled="cancelForm.processing"
                @click="cancel"
            >
                {{ labels.cancel }}
            </Button>
        </section>

        <section
            v-else-if="event.status === 'registration'"
            class="mt-8 space-y-6"
        >
            <h2 class="text-xl font-semibold text-foreground">
                {{ labels.choose_ticket }}
            </h2>
            <div class="space-y-2">
                <label
                    v-for="ticket in tickets"
                    :key="ticket"
                    class="flex items-center gap-3 rounded-md border border-border p-3 has-[:checked]:border-primary"
                >
                    <input
                        v-model="selected"
                        type="radio"
                        :value="ticket"
                        name="ticket"
                        class="accent-primary"
                    />
                    <span class="text-foreground">{{
                        ticketLabels[ticket] ?? ticket
                    }}</span>
                </label>
            </div>
            <Button :disabled="registerForm.processing" @click="submit">{{
                labels.register
            }}</Button>
        </section>

        <div
            v-else
            class="mt-8 rounded-lg border border-dashed border-border p-6 text-center"
        >
            <p class="text-sm text-muted-foreground">{{ labels.closed }}</p>
        </div>
    </PageContainer>
</template>
