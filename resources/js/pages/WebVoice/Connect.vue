<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import { MonitorSmartphone } from '@lucide/vue';
import DesktopClientLink from '@/components/DesktopClientLink.vue';
import PageContainer from '@/components/PageContainer.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Skeleton } from '@/components/ui/skeleton';
import { usePageLoadState } from '@/composables/usePageLoadState';

type Device = {
    id: number;
    name: string;
    created_at: string | null;
    last_used_at: string | null;
};

const props = defineProps<{
    state: 'empty' | 'pending' | 'invalid';
    code: string | null;
    deviceName: string | null;
    devices: Device[];
    labels: Record<string, string>;
}>();

const { isNavigating, hasError } = usePageLoadState();

const lookup = useForm({ code: '' });
const decision = useForm({ code: props.code ?? '', decision: 'approve' });

function find(): void {
    router.get('/talk/connect', { code: lookup.code });
}

function decide(choice: 'approve' | 'deny'): void {
    decision.decision = choice;
    decision.post('/talk/connect');
}

function revoke(id: number): void {
    router.delete(`/talk/devices/${id}`, { preserveScroll: true });
}

const formatDate = (iso: string | null): string =>
    iso === null
        ? props.labels.never_used
        : new Date(iso).toLocaleString('de-DE', {
              dateStyle: 'medium',
              timeStyle: 'short',
              timeZone: 'Europe/Berlin',
          });
</script>

<template>
    <Head :title="labels.title" />

    <PageContainer
        width="narrow"
        :title="labels.title"
        :description="labels.description"
        :back="{ href: '/talk', label: labels.back }"
    >
        <!-- pending: a live request the user can decide -->
        <section
            v-if="state === 'pending'"
            class="mt-8 rounded-md border border-border bg-card p-5"
            data-test="connect-confirm"
        >
            <h2 class="text-lg font-semibold tracking-tight">
                {{ labels.confirm_heading }}
            </h2>
            <p class="mt-2 text-sm text-muted-foreground">
                {{ labels.confirm_help }}
            </p>
            <dl class="mt-4 grid grid-cols-[auto_1fr] gap-x-4 gap-y-2 text-sm">
                <dt class="text-muted-foreground">{{ labels.device }}</dt>
                <dd class="font-medium break-words">{{ deviceName }}</dd>
                <dt class="text-muted-foreground">{{ labels.code }}</dt>
                <dd class="font-mono text-lg tracking-widest">{{ code }}</dd>
            </dl>
            <div class="mt-5 flex flex-wrap gap-3">
                <Button
                    type="button"
                    :disabled="decision.processing"
                    @click="decide('approve')"
                >
                    {{ labels.approve }}
                </Button>
                <Button
                    type="button"
                    variant="outline"
                    :disabled="decision.processing"
                    @click="decide('deny')"
                >
                    {{ labels.deny }}
                </Button>
            </div>
        </section>

        <!-- empty / invalid: ask for the code -->
        <form
            v-else
            class="mt-8 rounded-md border border-border bg-card p-5"
            data-test="connect-enter"
            @submit.prevent="find"
        >
            <h2 class="text-lg font-semibold tracking-tight">
                {{ labels.enter_heading }}
            </h2>
            <p class="mt-2 text-sm text-muted-foreground">
                {{ labels.enter_help }}
            </p>
            <p
                v-if="state === 'invalid'"
                class="mt-4 rounded-md border border-destructive/50 bg-destructive/10 p-3 text-sm text-destructive"
                role="alert"
                data-test="connect-invalid"
            >
                {{ labels.invalid }}
            </p>
            <div class="mt-4 grid gap-2">
                <Label for="connect-code">{{ labels.code_label }}</Label>
                <Input
                    id="connect-code"
                    v-model="lookup.code"
                    class="font-mono tracking-widest uppercase"
                    autocomplete="off"
                    autocapitalize="characters"
                    maxlength="12"
                    :placeholder="labels.code_placeholder"
                />
            </div>
            <Button class="mt-5" type="submit" :disabled="lookup.code === ''">
                {{ labels.continue }}
            </Button>
        </form>

        <DesktopClientLink class="mt-4" :label="labels.get_app" />

        <section class="mt-8" aria-labelledby="connected-devices">
            <h2
                id="connected-devices"
                class="text-lg font-semibold tracking-tight"
            >
                {{ labels.devices_heading }}
            </h2>

            <!-- loading -->
            <div
                v-if="isNavigating"
                class="mt-4 space-y-3"
                role="status"
                :aria-label="labels.devices_loading"
            >
                <Skeleton class="h-16 w-full" />
                <Skeleton class="h-16 w-full" />
            </div>

            <!-- error -->
            <p
                v-else-if="hasError"
                class="mt-4 rounded-md border border-destructive/50 bg-destructive/10 p-8 text-center text-sm text-destructive"
                role="alert"
            >
                {{ labels.devices_error }}
            </p>

            <!-- empty -->
            <p
                v-else-if="devices.length === 0"
                class="mt-4 rounded-md border border-dashed border-border p-6 text-sm text-muted-foreground"
                data-test="devices-empty"
            >
                {{ labels.devices_empty }}
            </p>

            <!-- normal -->
            <ul v-else class="mt-4 space-y-3" data-test="devices-list">
                <li
                    v-for="device in devices"
                    :key="device.id"
                    class="flex items-center justify-between gap-4 rounded-md border border-border bg-card p-4"
                >
                    <div class="flex min-w-0 items-start gap-3">
                        <MonitorSmartphone
                            class="mt-0.5 size-5 shrink-0 text-muted-foreground"
                            aria-hidden="true"
                        />
                        <div class="min-w-0 text-sm">
                            <p class="font-medium break-words">
                                {{ device.name }}
                            </p>
                            <p class="text-xs text-muted-foreground">
                                {{ labels.created }}
                                {{ formatDate(device.created_at) }}
                            </p>
                            <p class="text-xs text-muted-foreground">
                                {{ labels.last_used }}
                                {{ formatDate(device.last_used_at) }}
                            </p>
                        </div>
                    </div>
                    <Button
                        type="button"
                        variant="outline"
                        size="sm"
                        @click="revoke(device.id)"
                    >
                        {{ labels.revoke }}
                    </Button>
                </li>
            </ul>
        </section>
    </PageContainer>
</template>
