<script setup lang="ts">
// The warmup->live "Go" gong moment (Task 11): a helper/orga's manual "Go"
// (or, later, an automatic "all rosters ready" trigger) flips a match live
// and this synthetic override scene interrupts the beamer's rotation for a
// few seconds — one focal signal, not a celebration (that's SceneWinner's
// job with its confetti). A percussive amber ring pulses outward once from
// the "GO!" mark, echoing a struck gong, then keeps a calm static ring so
// latecomers to the animation still read the moment.
//
// Respects `prefers-reduced-motion` via Tailwind's `motion-reduce:` variant
// (mirrors LiveIndicator/SceneServers): with reduced motion, only the
// static amber ring and text show, no ping animation.
defineProps<{
    data: { tournament?: string; slot1?: string; slot2?: string };
    labels: Record<string, string>;
}>();
</script>

<template>
    <div class="relative flex h-full w-full items-center justify-center">
        <div class="relative flex items-center justify-center">
            <span
                class="absolute inline-flex size-64 animate-ping rounded-full bg-live opacity-40 motion-reduce:hidden"
            />
            <span
                class="absolute inline-flex size-64 rounded-full border-4 border-live"
            />
        </div>

        <div class="relative z-10 mx-auto max-w-5xl px-12 text-center">
            <h1
                class="text-9xl font-extrabold tracking-tight text-live uppercase"
            >
                {{ labels.gong_title }}
            </h1>
            <p
                v-if="data.slot1 || data.slot2"
                class="mt-8 text-5xl font-bold text-foreground"
            >
                {{
                    labels.gong_subtitle
                        .replace(':slot1', data.slot1 ?? '—')
                        .replace(':slot2', data.slot2 ?? '—')
                }}
            </p>
            <p
                v-if="data.tournament"
                class="mt-6 text-3xl text-muted-foreground"
            >
                {{ data.tournament }}
            </p>
        </div>
    </div>
</template>
