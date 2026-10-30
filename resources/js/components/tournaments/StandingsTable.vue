<script setup lang="ts">
import type { TournamentStandingRow } from '@/types';

defineProps<{
    rows: TournamentStandingRow[];
    myEntryId: number | null;
    labels: Record<string, string>;
}>();
</script>

<template>
    <section aria-labelledby="standings-heading">
        <h2
            id="standings-heading"
            class="text-lg font-semibold tracking-tight text-foreground"
        >
            {{ labels.title }}
        </h2>

        <p
            v-if="rows.length === 0"
            class="mt-3 rounded-lg border border-dashed border-border p-6 text-center text-sm text-muted-foreground"
        >
            {{ labels.empty }}
        </p>

        <div v-else class="mt-3 overflow-x-auto rounded-lg border border-border">
            <table class="w-full text-sm">
                <thead
                    class="bg-muted/40 text-left text-xs text-muted-foreground"
                >
                    <tr>
                        <th scope="col" class="px-3 py-2 font-medium">
                            {{ labels.rank }}
                        </th>
                        <th scope="col" class="px-3 py-2 font-medium">
                            {{ labels.name }}
                        </th>
                        <th scope="col" class="px-3 py-2 text-right font-medium">
                            {{ labels.played }}
                        </th>
                        <th scope="col" class="px-3 py-2 text-right font-medium">
                            {{ labels.wins }}
                        </th>
                        <th
                            scope="col"
                            class="hidden px-3 py-2 text-right font-medium sm:table-cell"
                        >
                            {{ labels.losses }}
                        </th>
                        <th
                            scope="col"
                            class="hidden px-3 py-2 text-right font-medium sm:table-cell"
                        >
                            {{ labels.points }}
                        </th>
                        <th scope="col" class="px-3 py-2 text-right font-medium">
                            {{ labels.difference }}
                        </th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="row in rows"
                        :key="row.entryId"
                        class="border-t border-border"
                        :class="
                            row.entryId === myEntryId
                                ? 'bg-primary/10 font-medium'
                                : ''
                        "
                    >
                        <td class="px-3 py-2 font-mono tabular-nums">
                            {{ row.rank }}
                        </td>
                        <td class="max-w-48 truncate px-3 py-2">
                            {{ row.name }}
                        </td>
                        <td
                            class="px-3 py-2 text-right font-mono tabular-nums"
                        >
                            {{ row.played }}
                        </td>
                        <td
                            class="px-3 py-2 text-right font-mono tabular-nums"
                        >
                            {{ row.wins }}
                        </td>
                        <td
                            class="hidden px-3 py-2 text-right font-mono tabular-nums sm:table-cell"
                        >
                            {{ row.losses }}
                        </td>
                        <td
                            class="hidden px-3 py-2 text-right font-mono tabular-nums sm:table-cell"
                        >
                            {{ row.pointsFor }}:{{ row.pointsAgainst }}
                        </td>
                        <td
                            class="px-3 py-2 text-right font-mono tabular-nums"
                        >
                            {{
                                row.scoreDifference > 0
                                    ? `+${row.scoreDifference}`
                                    : row.scoreDifference
                            }}
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
        <p v-if="rows.length > 0" class="mt-2 text-xs text-muted-foreground">
            {{ labels.tiebreak }}
        </p>
    </section>
</template>
