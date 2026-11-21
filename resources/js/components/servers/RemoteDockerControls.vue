<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    command as commandRoute,
    logs as logsRoute,
    power as powerRoute,
} from '@/routes/servers/remote';
import type { GameServerDto } from '@/types';

// Power, console and logs of a server that runs as a container on a remote host. The parent
// renders this only for a server that sent `canManage` with `admin.remote`; every route
// authorizes again (policy `configure`), nothing here decides who may do what.
const props = defineProps<{
    server: GameServerDto;
    remote: NonNullable<NonNullable<GameServerDto['admin']>['remote']>;
}>();

type Signal = 'start' | 'stop' | 'restart';

const HISTORY_LIMIT = 10;
const l = computed(() => props.remote.labels);

const pending = ref<Signal | null>(null);
const busy = ref(false);
const command = ref('');
const sending = ref(false);
const answer = ref<string | null>(null);
const error = ref<string | null>(null);
const history = ref<string[]>([]);
const quick = ref('');

const logLines = ref(100);
const logs = ref<string | null>(null);
const logsLoading = ref(false);
const logsError = ref<string | null>(null);

const dialogOpen = computed({
    get: () => pending.value !== null,
    set: (open: boolean) => {
        if (!open && !busy.value) {
            pending.value = null;
        }
    },
});

function quickLabel(cmd: string): string {
    const key = cmd.trim();

    return props.remote.quickLabels[key] ?? key;
}

function useQuick(value: string) {
    command.value = value;
    quick.value = '';
}

function xsrfToken(): string {
    const match = document.cookie
        .split('; ')
        .find((part) => part.startsWith('XSRF-TOKEN='));

    return match ? decodeURIComponent(match.slice('XSRF-TOKEN='.length)) : '';
}

function ask(signal: Signal) {
    // Starting needs no confirmation, stopping and restarting disconnect players.
    if (signal === 'start') {
        runPower(signal);
    } else {
        pending.value = signal;
    }
}

function runPower(signal: Signal) {
    router.post(
        powerRoute.url(props.server.id),
        { action: signal },
        {
            preserveScroll: true,
            onStart: () => (busy.value = true),
            // The success or error toast comes back as the flash message.
            onFinish: () => {
                busy.value = false;
                pending.value = null;
            },
        },
    );
}

async function send() {
    const value = command.value.trim();

    if (value === '' || sending.value) {
        return;
    }

    sending.value = true;
    error.value = null;

    try {
        const response = await fetch(commandRoute.url(props.server.id), {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                Accept: 'application/json',
                'Content-Type': 'application/json',
                'X-XSRF-TOKEN': xsrfToken(),
            },
            body: JSON.stringify({ command: value }),
        });
        const data = (await response.json().catch(() => ({}))) as {
            output?: string;
            message?: string;
        };

        if (!response.ok || data.output === undefined) {
            answer.value = null;
            error.value =
                response.status === 429
                    ? l.value.error
                    : (data.message ?? l.value.error);

            return;
        }

        answer.value = data.output;
        history.value = [
            value,
            ...history.value.filter((h) => h !== value),
        ].slice(0, HISTORY_LIMIT);
        command.value = '';
    } catch {
        error.value = l.value.error;
    } finally {
        sending.value = false;
    }
}

async function loadLogs() {
    logsLoading.value = true;
    logsError.value = null;

    try {
        const lines = Math.min(
            500,
            Math.max(1, Math.trunc(logLines.value || 100)),
        );
        const response = await fetch(
            `${logsRoute.url(props.server.id)}?lines=${lines}`,
            {
                headers: { Accept: 'application/json' },
                credentials: 'same-origin',
            },
        );
        const data = (await response.json().catch(() => ({}))) as {
            output?: string;
            message?: string;
        };

        if (!response.ok || data.output === undefined) {
            logs.value = null;
            logsError.value = data.message ?? l.value.error;

            return;
        }

        logs.value = data.output;
    } catch {
        logsError.value = l.value.error;
    } finally {
        logsLoading.value = false;
    }
}
</script>

<template>
    <section
        class="space-y-4 rounded-md border border-dashed border-border bg-muted/20 p-3"
        :aria-label="l.heading"
    >
        <h3 class="text-sm font-medium text-muted-foreground">
            {{ l.heading }}
        </h3>

        <div class="flex flex-wrap items-center gap-2">
            <Button
                type="button"
                size="sm"
                variant="outline"
                :disabled="busy"
                @click="ask('start')"
            >
                {{ l.start }}
            </Button>
            <Button
                type="button"
                size="sm"
                variant="outline"
                :disabled="busy"
                @click="ask('restart')"
            >
                {{ l.restart }}
            </Button>
            <Button
                type="button"
                size="sm"
                variant="outline"
                :disabled="busy"
                @click="ask('stop')"
            >
                {{ l.stop }}
            </Button>
        </div>

        <form class="grid gap-3" @submit.prevent="send">
            <div v-if="remote.quickCommands.length" class="grid gap-1.5">
                <Label :for="`remote-quick-${server.id}`">{{ l.quick }}</Label>
                <select
                    :id="`remote-quick-${server.id}`"
                    v-model="quick"
                    class="h-9 w-full rounded-md border border-input bg-background px-2 text-sm focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                    @change="useQuick(quick)"
                >
                    <option value="">{{ l.quick_placeholder }}</option>
                    <option
                        v-for="q in remote.quickCommands"
                        :key="q"
                        :value="q"
                    >
                        {{ quickLabel(q) }}
                    </option>
                </select>
            </div>

            <div class="grid gap-1.5">
                <Label :for="`remote-command-${server.id}`">{{
                    l.command
                }}</Label>
                <div class="flex gap-2">
                    <Input
                        :id="`remote-command-${server.id}`"
                        v-model="command"
                        class="font-mono"
                        maxlength="256"
                        autocomplete="off"
                        spellcheck="false"
                        :aria-describedby="`remote-command-help-${server.id}`"
                    />
                    <Button
                        type="submit"
                        size="sm"
                        :disabled="sending || command.trim() === ''"
                    >
                        {{ sending ? l.sending : l.send }}
                    </Button>
                </div>
                <p
                    :id="`remote-command-help-${server.id}`"
                    class="text-xs text-muted-foreground"
                >
                    {{ l.command_help }}
                </p>
            </div>

            <p v-if="error" role="alert" class="text-sm text-destructive">
                {{ error }}
            </p>

            <div v-if="answer !== null" class="grid gap-1.5">
                <p class="text-xs text-muted-foreground">{{ l.answer }}</p>
                <pre
                    role="log"
                    aria-live="polite"
                    class="max-h-72 overflow-auto rounded-md border bg-card p-2 font-mono text-xs break-words whitespace-pre-wrap"
                    >{{ answer !== '' ? answer : l.no_answer }}</pre>
            </div>

            <div v-if="history.length" class="grid gap-1">
                <p class="text-xs text-muted-foreground">{{ l.history }}</p>
                <ul class="flex flex-wrap gap-1.5">
                    <li v-for="h in history" :key="h">
                        <button
                            type="button"
                            class="rounded border bg-card px-1.5 py-0.5 font-mono text-xs hover:bg-muted focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                            @click="command = h"
                        >
                            {{ h }}
                        </button>
                    </li>
                </ul>
            </div>
        </form>

        <details class="group">
            <summary
                class="cursor-pointer text-sm font-medium focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
            >
                {{ l.logs }}
            </summary>
            <div class="mt-2 grid gap-2">
                <div class="flex items-end gap-2">
                    <div class="grid gap-1.5">
                        <Label :for="`remote-lines-${server.id}`">{{
                            l.logs_lines
                        }}</Label>
                        <Input
                            :id="`remote-lines-${server.id}`"
                            v-model.number="logLines"
                            type="number"
                            min="1"
                            max="500"
                            class="w-24 font-mono"
                        />
                    </div>
                    <Button
                        type="button"
                        size="sm"
                        variant="outline"
                        :disabled="logsLoading"
                        @click="loadLogs"
                    >
                        {{ logsLoading ? l.logs_loading : l.logs_load }}
                    </Button>
                </div>
                <p
                    v-if="logsError"
                    role="alert"
                    class="text-sm text-destructive"
                >
                    {{ logsError }}
                </p>
                <pre
                    v-if="logs !== null"
                    role="log"
                    class="max-h-80 overflow-auto rounded-md border bg-card p-2 font-mono text-xs break-words whitespace-pre-wrap"
                    >{{ logs !== '' ? logs : l.logs_empty }}</pre>
            </div>
        </details>

        <Dialog v-model:open="dialogOpen">
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>{{ server.title ?? server.game }}</DialogTitle>
                    <DialogDescription>
                        {{
                            pending === 'stop'
                                ? l.stop_confirm
                                : l.restart_confirm
                        }}
                    </DialogDescription>
                </DialogHeader>
                <DialogFooter class="gap-2">
                    <Button
                        type="button"
                        variant="outline"
                        :disabled="busy"
                        @click="pending = null"
                    >
                        {{ l.cancel }}
                    </Button>
                    <Button
                        type="button"
                        :disabled="busy"
                        @click="pending && runPower(pending)"
                    >
                        {{ busy ? l.sending : l.confirm }}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    </section>
</template>
