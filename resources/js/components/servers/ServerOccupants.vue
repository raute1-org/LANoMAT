<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { computed } from 'vue';
import UserAvatar from '@/components/UserAvatar.vue';
import type { ServerOccupantsDto } from '@/types';

/**
 * The quiet "Hier spielen" line of a server row: up to five faces of the LANoMAT
 * accounts Presence places on it (as the viewer may see them), their names where the
 * row has room and a count for the rest. It renders nothing when there is nobody to
 * name, so a row without people keeps its height. No amber: this is a hint, not an
 * action. The full, longer list lives on "Wo ist wer".
 */
const props = defineProps<{
    /** `null` while Presence is off or nobody is logged in. */
    occupants: ServerOccupantsDto | null;
    /** `gameservers.page` labels. */
    labels: Record<string, string>;
}>();

/** How many faces the line shows before it turns into "+N". */
const MAX_AVATARS = 5;

const people = computed(() => props.occupants?.people ?? []);

const shown = computed(() => people.value.slice(0, MAX_AVATARS));

/**
 * Everybody without a face in this line: the rest of the eight named accounts plus the
 * ones only counted. A hidden person is in neither number.
 */
const rest = computed(
    () =>
        people.value.length - shown.value.length + (props.occupants?.more ?? 0),
);

const names = computed(() =>
    shown.value.map((person) => person.name).join(', '),
);
</script>

<template>
    <p
        v-if="shown.length > 0"
        class="flex min-w-0 flex-wrap items-center gap-x-2 gap-y-1 text-xs text-muted-foreground"
    >
        <span class="shrink-0">{{ labels.occupants_label }}</span>

        <span class="flex items-center gap-1">
            <Link
                v-for="person in shown"
                :key="person.id"
                :href="`/users/${person.id}`"
                :title="person.name"
                :aria-label="person.name"
                class="rounded-full outline-none focus-visible:ring-2 focus-visible:ring-ring"
            >
                <UserAvatar
                    :name="person.name"
                    :src="person.avatarUrl"
                    :size="24"
                />
            </Link>
        </span>

        <span
            v-if="names"
            class="hidden min-w-0 truncate sm:block"
            aria-hidden="true"
        >
            {{ names }}
        </span>

        <span v-if="rest > 0" class="shrink-0 tabular-nums" aria-hidden="true">
            +{{ rest }}
        </span>
        <span v-if="rest > 0" class="sr-only">
            {{ labels.occupants_more.replace(':count', String(rest)) }}
        </span>
    </p>
</template>
