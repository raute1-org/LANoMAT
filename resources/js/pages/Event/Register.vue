<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';
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
    registration: RegistrationDetails | null;
    labels: Record<string, string>;
    statusLabels: Record<string, string>;
}>();

const selected = ref(props.tickets[0] ?? 'standard');

const registerForm = useForm({ ticket_type: selected.value });
const cancelForm = useForm({});

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

    <main class="mx-auto max-w-lg px-4 py-12">
        <h1 class="text-3xl font-bold tracking-tight">{{ event.name }}</h1>

        <section v-if="registration" class="mt-8 space-y-6">
            <h2 class="text-xl font-semibold">{{ labels.my_registration }}</h2>
            <div class="rounded-lg border border-border p-4">
                <!-- eslint-disable-next-line vue/no-v-html -- server-rendered QR SVG, no user input -->
                <div class="mx-auto w-64" v-html="registration.qrSvg" />
                <p class="mt-3 text-center text-sm text-muted-foreground">
                    {{ labels.qr_hint }}
                </p>
            </div>
            <dl class="grid grid-cols-2 gap-2 text-sm">
                <dt class="text-muted-foreground">{{ labels.ticket }}</dt>
                <dd>{{ registration.ticketType }}</dd>
                <dt class="text-muted-foreground">{{ labels.status }}</dt>
                <dd>{{ registration.paid ? labels.paid : labels.unpaid }}</dd>
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
            <h2 class="text-xl font-semibold">{{ labels.choose_ticket }}</h2>
            <div class="space-y-2">
                <label
                    v-for="ticket in tickets"
                    :key="ticket"
                    class="flex items-center gap-3 rounded-md border border-border p-3"
                >
                    <input
                        v-model="selected"
                        type="radio"
                        :value="ticket"
                        name="ticket"
                    />
                    <span>{{ ticket }}</span>
                </label>
            </div>
            <Button :disabled="registerForm.processing" @click="submit">{{
                labels.register
            }}</Button>
        </section>

        <p v-else class="mt-8 text-muted-foreground">{{ labels.closed }}</p>

        <Link
            :href="eventsShow.url(event.slug)"
            class="mt-6 inline-block text-sm underline"
        >
            {{ event.name }}
        </Link>
    </main>
</template>
