<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { ExternalLink } from '@lucide/vue';
import { formatInstant } from '@/lib/datetime';
import type { GameShareShare } from '@/types/gameShare';

/**
 * The viewer's own shared games, each as a three-step status a participant
 * reads without knowing the words "recipe" or "pull request": datei (the
 * archive), prüfung (the orga's review), katalog (the automated catalogue
 * entry). The catalogue step is the only one that links away.
 */
defineProps<{ shares: GameShareShare[] }>();

type Tone = 'muted' | 'warn' | 'ok' | 'down';

const toneClass: Record<Tone, string> = {
    muted: 'text-muted-foreground',
    warn: 'text-warn',
    ok: 'text-ok',
    down: 'text-destructive',
};

function fileStep(share: GameShareShare): { label: string; tone: Tone } {
    if (share.file === null) {
        return { label: 'fehlt', tone: 'down' };
    }

    switch (share.file.visibility) {
        case 'pending':
            return { label: 'wartet auf Freigabe', tone: 'warn' };
        case 'approved':
            return { label: 'freigegeben', tone: 'ok' };
        case 'rejected':
            return { label: 'abgelehnt', tone: 'down' };
    }
}

function reviewStep(share: GameShareShare): { label: string; tone: Tone } {
    switch (share.status) {
        case 'draft':
            return { label: 'Die Orga prüft dein Spiel', tone: 'warn' };
        case 'published':
            return {
                label: share.statusLabel || 'Veröffentlicht',
                tone: 'ok',
            };
        case 'archived':
            return { label: share.statusLabel || 'Archiviert', tone: 'muted' };
    }
}

function formatDateTime(iso: string): string {
    return formatInstant(iso, { dateStyle: 'short', timeStyle: 'short' });
}
</script>

<template>
    <!-- empty: an invitation, not a bare "nothing here" -->
    <p
        v-if="shares.length === 0"
        class="border-t border-border px-4 py-3 text-sm text-muted-foreground"
    >
        Noch kein Spiel geteilt. Lade oben ein Archiv hoch, beschreibe das Spiel
        im zweiten Schritt — dann prüft die Orga es und gibt es für alle frei.
    </p>

    <ul v-else class="divide-y divide-border border-t border-border">
        <li v-for="share in shares" :key="share.slug" class="px-4 py-3">
            <div class="flex flex-wrap items-start justify-between gap-2">
                <h4 class="min-w-0 text-sm font-medium break-words">
                    {{ share.title }}
                </h4>
                <div class="flex shrink-0 flex-wrap items-center gap-2">
                    <span
                        v-if="share.inLineup"
                        class="font-mono text-[10px] text-muted-foreground"
                    >
                        Im Launcher
                    </span>
                    <Link
                        v-if="share.gameUrl"
                        :href="share.gameUrl"
                        class="inline-flex min-h-11 items-center rounded-sm text-sm font-medium text-foreground underline decoration-border underline-offset-2 transition-colors hover:decoration-primary focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none motion-reduce:transition-none sm:min-h-0"
                    >
                        Zum Spiel
                    </Link>
                </div>
            </div>

            <dl class="mt-2 grid grid-cols-1 gap-2 text-xs sm:grid-cols-3">
                <div>
                    <dt class="text-muted-foreground">Datei</dt>
                    <dd class="mt-0.5" :class="toneClass[fileStep(share).tone]">
                        {{ fileStep(share).label }}
                    </dd>
                </div>
                <div>
                    <dt class="text-muted-foreground">Prüfung</dt>
                    <dd
                        class="mt-0.5"
                        :class="toneClass[reviewStep(share).tone]"
                    >
                        {{ reviewStep(share).label }}
                    </dd>
                </div>
                <div>
                    <dt class="text-muted-foreground">Katalog</dt>
                    <dd class="mt-0.5">
                        <template v-if="share.pullRequest">
                            <a
                                v-if="share.pullRequest.url"
                                :href="share.pullRequest.url"
                                target="_blank"
                                rel="noreferrer"
                                class="inline-flex items-center gap-1 rounded-sm text-foreground underline decoration-border underline-offset-2 transition-colors hover:decoration-primary focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none motion-reduce:transition-none"
                            >
                                {{ share.pullRequest.label }}
                                <ExternalLink
                                    class="size-3"
                                    aria-hidden="true"
                                />
                            </a>
                            <span v-else class="text-foreground">{{
                                share.pullRequest.label
                            }}</span>
                        </template>
                        <template v-else>
                            <span
                                aria-hidden="true"
                                class="text-muted-foreground"
                                >—</span
                            >
                            <span class="sr-only"
                                >Noch kein Eintrag im Katalog</span
                            >
                        </template>
                    </dd>
                </div>
            </dl>

            <p class="mt-2 font-mono text-[11px] text-muted-foreground">
                <time :datetime="share.updatedAt" class="tabular-nums"
                    >Aktualisiert {{ formatDateTime(share.updatedAt) }}</time
                >
            </p>
        </li>
    </ul>
</template>
