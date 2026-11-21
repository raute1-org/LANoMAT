<script setup lang="ts">
import { computed, useId } from 'vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import type { Decision, PendingCard } from '@/types/assistant';

const props = defineProps<{
    card: PendingCard;
    /** The pick already made for this card while other cards of the turn are still open. */
    chosen: Decision | null;
    secret: string;
    busy: boolean;
    /** The orga wrote a new message: this card can no longer be decided. */
    superseded: boolean;
}>();

const emit = defineEmits<{
    decide: [callId: string, decision: Decision];
    'update:secret': [value: string];
    undo: [callId: string];
}>();

const headingId = useId();
const secretId = useId();
const readOnly = computed(
    () => props.superseded || !props.card.decidable || props.busy,
);
const needsSecretNow = computed(
    () => props.card.preview.needsSecret && props.secret.trim() === '',
);

function approve(): void {
    if (!needsSecretNow.value) {
        emit('decide', props.card.callId, 'approve');
    }
}
</script>

<template>
    <section
        class="rounded-md border bg-card p-4"
        :class="[
            card.preview.danger && !superseded
                ? 'border-destructive/50'
                : 'border-border',
            superseded ? 'opacity-70' : '',
        ]"
        :aria-labelledby="headingId"
    >
        <div class="flex flex-wrap items-baseline justify-between gap-2">
            <h3 :id="headingId" class="text-sm font-semibold text-foreground">
                {{ card.preview.title }}
            </h3>
            <span
                v-if="superseded"
                class="text-xs text-muted-foreground"
                role="status"
            >
                Überholt, wird nicht ausgeführt
            </span>
            <span
                v-else-if="busy"
                class="text-xs text-muted-foreground"
                role="status"
            >
                Wird ausgeführt …
            </span>
        </div>

        <dl
            class="mt-3 grid grid-cols-[minmax(0,9rem)_minmax(0,1fr)] gap-x-4 gap-y-1.5 text-sm"
        >
            <template v-for="(row, index) in card.preview.rows" :key="index">
                <dt class="text-muted-foreground">{{ row.label }}</dt>
                <dd
                    class="min-w-0 break-words text-foreground"
                    :class="row.mono ? 'font-mono text-[0.8125rem]' : ''"
                >
                    {{ row.value }}
                </dd>
            </template>
        </dl>

        <div
            v-if="card.preview.needsSecret && !readOnly && chosen !== 'reject'"
            class="mt-3 space-y-1.5"
        >
            <Label :for="secretId" class="text-sm">Neues Passwort</Label>
            <Input
                :id="secretId"
                :model-value="secret"
                type="password"
                autocomplete="new-password"
                maxlength="128"
                :disabled="chosen === 'approve'"
                @update:model-value="emit('update:secret', String($event))"
            />
            <p class="text-xs text-muted-foreground">
                Wird nur hier eingegeben und gesetzt. Es geht nicht in den Chat
                und nicht an den Modell-Anbieter.
            </p>
        </div>

        <div
            v-if="!readOnly"
            class="mt-4 flex flex-wrap items-center gap-2"
            role="group"
            :aria-label="`Entscheidung: ${card.preview.title}`"
        >
            <template v-if="chosen === null">
                <Button
                    type="button"
                    :variant="card.preview.danger ? 'destructive' : 'default'"
                    :disabled="needsSecretNow"
                    @click="approve"
                >
                    Ausführen
                </Button>
                <Button
                    type="button"
                    variant="outline"
                    @click="emit('decide', card.callId, 'reject')"
                >
                    Ablehnen
                </Button>
            </template>
            <template v-else>
                <span class="text-sm text-muted-foreground" role="status">
                    {{
                        chosen === 'approve'
                            ? 'Vorgemerkt: ausführen'
                            : 'Vorgemerkt: ablehnen'
                    }}
                </span>
                <Button
                    type="button"
                    variant="ghost"
                    size="sm"
                    @click="emit('undo', card.callId)"
                >
                    Ändern
                </Button>
            </template>
        </div>
    </section>
</template>
