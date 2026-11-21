<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { Plus, Trash2 } from '@lucide/vue';
import { Button } from '@/components/ui/button';
import { index, show } from '@/routes/orga/assistant';
import type { ConversationSummary } from '@/types/assistant';

defineProps<{
    conversations: ConversationSummary[];
    activeId: string | null;
}>();

defineEmits<{ remove: [conversation: ConversationSummary] }>();
</script>

<template>
    <nav aria-label="Verlauf" class="flex flex-col gap-2">
        <Button as-child variant="outline" size="sm" class="justify-start">
            <Link :href="index()">
                <Plus class="size-4" aria-hidden="true" />
                Neue Unterhaltung
            </Link>
        </Button>

        <p
            v-if="conversations.length === 0"
            class="px-1 text-sm text-muted-foreground"
        >
            Noch keine Unterhaltungen.
        </p>

        <ul v-else class="space-y-0.5">
            <li
                v-for="conversation in conversations"
                :key="conversation.id"
                class="group flex items-center gap-1 rounded-md"
                :class="
                    conversation.id === activeId
                        ? 'bg-accent'
                        : 'hover:bg-accent/60'
                "
            >
                <Link
                    :href="show(conversation.id)"
                    class="min-w-0 flex-1 truncate rounded-md px-2 py-1.5 text-sm text-foreground outline-none focus-visible:ring-2 focus-visible:ring-ring pointer-coarse:py-2.5"
                    :aria-current="
                        conversation.id === activeId ? 'page' : undefined
                    "
                >
                    {{ conversation.title }}
                </Link>
                <button
                    type="button"
                    class="mr-1 rounded-sm p-1.5 text-muted-foreground opacity-0 transition-colors outline-none group-focus-within:opacity-100 group-hover:opacity-100 hover:text-destructive focus-visible:ring-2 focus-visible:ring-ring pointer-coarse:opacity-100"
                    :aria-label="`Unterhaltung löschen: ${conversation.title}`"
                    @click="$emit('remove', conversation)"
                >
                    <Trash2 class="size-4" aria-hidden="true" />
                </button>
            </li>
        </ul>
    </nav>
</template>
