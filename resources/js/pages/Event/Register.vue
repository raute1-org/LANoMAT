<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import ArrivalPlanFields from '@/components/ArrivalPlanFields.vue';
import LiveIndicator from '@/components/LiveIndicator.vue';
import PageContainer from '@/components/PageContainer.vue';
import { Button } from '@/components/ui/button';
import { show as eventsShow } from '@/routes/events';
import { arrival as arrivalRoute } from '@/routes/events/register';
import {
    destroy as destroyRegistration,
    store as storeRegistration,
} from '@/routes/events/register';
import type { ArrivalOptions, RegistrationDetails } from '@/types';

const props = defineProps<{
    event: { name: string; slug: string; status: string };
    availability: 'upcoming' | 'open' | 'full' | 'closed';
    freeSpots: number | null;
    tickets: string[];
    ticketLabels: Record<string, string>;
    registration: RegistrationDetails | null;
    arrivalOptions: ArrivalOptions;
    arrivalLabels: Record<string, any>;
    labels: Record<string, string>;
    cancelBlockedLabels: Record<string, string>;
    statusLabels: Record<string, string>;
}>();

const selected = ref(props.tickets[0] ?? 'standard');

const registerForm = useForm({
    ticket_type: selected.value,
    arrival_day: '',
    arrival_window: '',
    departure_day: '',
    departure_window: '',
});

const hasDays = computed(() => props.arrivalOptions.days.length > 0);

// Editing the plan after registering.
const editing = ref(false);
const arrivalForm = useForm({
    arrival_day: props.registration?.arrival.arrivalDay ?? '',
    arrival_window: props.registration?.arrival.arrivalWindow ?? '',
    departure_day: props.registration?.arrival.departureDay ?? '',
    departure_window: props.registration?.arrival.departureWindow ?? '',
});

function startEditing() {
    arrivalForm.defaults({
        arrival_day: props.registration?.arrival.arrivalDay ?? '',
        arrival_window: props.registration?.arrival.arrivalWindow ?? '',
        departure_day: props.registration?.arrival.departureDay ?? '',
        departure_window: props.registration?.arrival.departureWindow ?? '',
    });
    arrivalForm.reset();
    arrivalForm.clearErrors();
    editing.value = true;
}

function saveArrival() {
    arrivalForm.patch(arrivalRoute.url({ event: props.event.slug }), {
        preserveScroll: true,
        onSuccess: () => {
            editing.value = false;
        },
    });
}
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

            <div
                v-if="hasDays"
                id="arrival"
                class="space-y-3 rounded-md border border-border bg-card p-4"
                data-test="arrival-plan"
            >
                <div class="flex items-center justify-between gap-3">
                    <h3 class="text-lg font-semibold tracking-tight">
                        {{ arrivalLabels.heading }}
                    </h3>
                    <Button
                        v-if="registration.arrival.editable && !editing"
                        variant="outline"
                        size="sm"
                        data-test="arrival-edit"
                        @click="startEditing"
                    >
                        {{
                            registration.arrival.arrivalLabel ||
                            registration.arrival.departureLabel
                                ? arrivalLabels.change
                                : arrivalLabels.set
                        }}
                    </Button>
                </div>

                <template v-if="editing">
                    <ArrivalPlanFields
                        v-model="arrivalForm"
                        :options="arrivalOptions"
                        :labels="arrivalLabels"
                        :errors="arrivalForm.errors"
                    />
                    <div class="flex gap-2">
                        <Button
                            :disabled="arrivalForm.processing"
                            data-test="arrival-save"
                            @click="saveArrival"
                        >
                            {{ arrivalLabels.save }}
                        </Button>
                        <Button variant="outline" @click="editing = false">
                            {{ arrivalLabels.cancel }}
                        </Button>
                    </div>
                </template>
                <template v-else>
                    <dl class="grid grid-cols-2 gap-y-2 text-sm">
                        <dt class="text-muted-foreground">
                            {{ arrivalLabels.arrival }}
                        </dt>
                        <dd class="text-foreground">
                            {{
                                registration.arrival.arrivalLabel ??
                                arrivalLabels.open
                            }}
                        </dd>
                        <dt class="text-muted-foreground">
                            {{ arrivalLabels.departure }}
                        </dt>
                        <dd class="text-foreground">
                            {{
                                registration.arrival.departureLabel ??
                                arrivalLabels.open
                            }}
                        </dd>
                    </dl>
                    <p
                        v-if="!registration.arrival.editable"
                        class="text-sm text-muted-foreground"
                    >
                        {{ arrivalLabels.locked }}
                    </p>
                </template>
            </div>

            <Button
                v-if="registration.canCancel"
                variant="destructive"
                :disabled="cancelForm.processing"
                @click="cancel"
            >
                {{ labels.cancel }}
            </Button>
            <p
                v-else-if="registration.cancelBlockedReason"
                class="text-sm text-muted-foreground"
                data-test="cancel-blocked"
            >
                {{ cancelBlockedLabels[registration.cancelBlockedReason] }}
            </p>
        </section>

        <section v-else-if="availability === 'open'" class="mt-8 space-y-6">
            <h2 class="text-xl font-semibold text-foreground">
                {{ labels.choose_ticket }}
            </h2>
            <p
                v-if="freeSpots !== null"
                class="text-sm text-muted-foreground"
                data-test="register-free-spots"
            >
                {{ labels.free_spots.replace(':count', String(freeSpots)) }}
            </p>
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
            <div v-if="hasDays" class="space-y-3" data-test="arrival-section">
                <h3 class="text-lg font-semibold tracking-tight">
                    {{ arrivalLabels.heading }}
                </h3>
                <ArrivalPlanFields
                    v-model="registerForm"
                    :options="arrivalOptions"
                    :labels="arrivalLabels"
                    :errors="registerForm.errors"
                />
            </div>
            <Button :disabled="registerForm.processing" @click="submit">{{
                labels.register
            }}</Button>
        </section>

        <div
            v-else
            class="mt-8 rounded-lg border border-dashed border-border p-6 text-center"
        >
            <p class="text-sm text-muted-foreground">
                {{ availability === 'full' ? labels.full : labels.closed }}
            </p>
        </div>
    </PageContainer>
</template>
