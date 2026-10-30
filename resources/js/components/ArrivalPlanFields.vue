<script setup lang="ts">
import type { ArrivalOptions } from '@/types';

export type ArrivalValue = {
    arrival_day: string;
    arrival_window: string;
    departure_day: string;
    departure_window: string;
};

const props = defineProps<{
    options: ArrivalOptions;
    labels: Record<string, any>;
    errors?: Partial<Record<keyof ArrivalValue, string>>;
}>();

const model = defineModel<ArrivalValue>({ required: true });

// Picking a day pre-selects a sensible window so the pair is never half-filled;
// clearing the day clears the window ("weiß ich noch nicht").
const DEFAULT_WINDOW: Record<'arrival' | 'departure', string> = {
    arrival: 'afternoon',
    departure: 'afternoon',
};

function onDay(kind: 'arrival' | 'departure', event: Event) {
    const value = (event.target as HTMLSelectElement).value;
    model.value[`${kind}_day`] = value;

    if (value === '') {
        model.value[`${kind}_window`] = '';
    } else if (model.value[`${kind}_window`] === '') {
        model.value[`${kind}_window`] = DEFAULT_WINDOW[kind];
    }
}

const selectClass =
    'h-11 w-full rounded-md border border-border bg-background px-3 text-base text-foreground focus-visible:border-ring focus-visible:ring-2 focus-visible:ring-ring/50 focus-visible:outline-none disabled:cursor-not-allowed disabled:opacity-50 sm:h-9 sm:text-sm';

const kinds = ['arrival', 'departure'] as const;

function error(kind: 'arrival' | 'departure') {
    return props.errors?.[`${kind}_day`] ?? props.errors?.[`${kind}_window`];
}
</script>

<template>
    <div class="space-y-4" data-test="arrival-fields">
        <p class="text-sm text-muted-foreground">{{ labels.hint }}</p>

        <fieldset v-for="kind in kinds" :key="kind" class="space-y-2">
            <legend class="text-sm font-medium text-foreground">
                {{ labels[kind] }}
            </legend>
            <div class="grid gap-2 sm:grid-cols-2">
                <label class="block">
                    <span class="sr-only">
                        {{ labels[kind] }}: {{ labels.day }}
                    </span>
                    <select
                        :value="model[`${kind}_day`]"
                        :class="selectClass"
                        :data-test="`${kind}-day`"
                        @change="onDay(kind, $event)"
                    >
                        <option value="">{{ labels.unknown_option }}</option>
                        <option
                            v-for="day in options.days"
                            :key="day.value"
                            :value="day.value"
                        >
                            {{ day.label }}
                        </option>
                    </select>
                </label>
                <label class="block">
                    <span class="sr-only">
                        {{ labels[kind] }}: {{ labels.window }}
                    </span>
                    <select
                        v-model="model[`${kind}_window`]"
                        :disabled="model[`${kind}_day`] === ''"
                        :class="selectClass"
                        :data-test="`${kind}-window`"
                    >
                        <option value="" disabled>{{ labels.window }}</option>
                        <option
                            v-for="window in options.windows"
                            :key="window.value"
                            :value="window.value"
                        >
                            {{ window.label }}
                        </option>
                    </select>
                </label>
            </div>
            <p v-if="error(kind)" class="text-sm text-destructive" role="alert">
                {{ error(kind) }}
            </p>
        </fieldset>
    </div>
</template>
