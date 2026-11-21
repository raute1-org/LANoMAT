<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import { MonitorSmartphone } from '@lucide/vue';
import PageContainer from '@/components/PageContainer.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { usePageLoadState } from '@/composables/usePageLoadState';

type Device = {
    id: number;
    name: string;
    createdAt: string | null;
    lastUsedAt: string | null;
};

const props = defineProps<{
    state: 'form' | 'pending' | 'invalid';
    code: string | null;
    deviceName: string | null;
    devices: Device[];
    labels: Record<string, string>;
}>();

const { isNavigating, hasError } = usePageLoadState();

const lookup = useForm({ code: '' });
const decision = useForm({ code: props.code ?? '', decision: 'approve' });

function find(): void {
    router.get('/mcp/connect', { code: lookup.code });
}

function decide(choice: 'approve' | 'deny'): void {
    decision.decision = choice;
    decision.post('/mcp/connect');
}

function revoke(id: number): void {
    router.delete(`/mcp/devices/${id}`, { preserveScroll: true });
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
    >
        <section
            v-if="state === 'pending'"
            class="mt-8 rounded-md border border-border bg-card p-5"
            data-test="mcp-connect-confirm"
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

        <form
            v-else
            class="mt-8 rounded-md border border-border bg-card p-5"
            data-test="mcp-connect-enter"
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
                data-test="mcp-connect-invalid"
            >
                {{ labels.invalid }}
            </p>
            <div class="mt-4 grid gap-2">
                <Label for="mcp-code">{{ labels.code_label }}</Label>
                <Input
                    id="mcp-code"
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

        <section class="mt-10">
            <h2
                class="flex items-center gap-2 text-lg font-semibold tracking-tight"
            >
                <MonitorSmartphone
                    class="size-4 text-muted-foreground"
                    aria-hidden="true"
                />
                {{ labels.devices_heading }}
            </h2>

            <div
                v-if="devices.length === 0"
                class="mt-3 rounded-md border border-dashed border-border p-4 text-sm text-muted-foreground"
                data-test="mcp-devices-empty"
            >
                <p>{{ labels.devices_empty }}</p>
                <p class="mt-2">{{ labels.devices_empty_hint }}</p>
                <ul class="mt-2 list-disc space-y-1 pl-5">
                    <li>{{ labels.devices_empty_launcher }}</li>
                    <li>
                        {{ labels.devices_empty_agent }}
                        <code
                            class="rounded-md bg-muted px-1.5 py-0.5 font-mono text-xs text-foreground"
                            >{{ labels.devices_empty_command }}</code
                        >
                    </li>
                </ul>
            </div>

            <ul
                v-else
                class="mt-3 divide-y divide-border rounded-md border border-border"
            >
                <li
                    v-for="device in devices"
                    :key="device.id"
                    class="flex flex-wrap items-center justify-between gap-3 p-4"
                >
                    <div>
                        <p class="font-medium">{{ device.name }}</p>
                        <p class="font-mono text-xs text-muted-foreground">
                            {{ labels.created }}
                            {{ formatDate(device.createdAt) }} ·
                            {{ labels.last_used }}
                            {{ formatDate(device.lastUsedAt) }}
                        </p>
                    </div>
                    <Button
                        type="button"
                        variant="outline"
                        size="sm"
                        :disabled="isNavigating || hasError"
                        @click="revoke(device.id)"
                    >
                        {{ labels.revoke }}
                    </Button>
                </li>
            </ul>
        </section>
    </PageContainer>
</template>
