<script setup lang="ts">
// Full-viewport wrapper for public OBS browser-source overlays (bracket,
// scoreboard, ...). Deliberately mirrors `SceneFrame.vue` (the beamer's
// chrome wrapper) with exactly one difference: the background is
// `bg-transparent`, never `bg-background` — a browser source composites
// this page directly over gameplay footage, so an opaque background would
// black out the stream instead of overlaying it.
//
// `dark` is still forced on this subtree so semantic tokens (text colors,
// the live indicator, etc.) resolve to their beamer/dark values regardless
// of OBS's own color scheme — only the background itself is stripped out.
</script>

<template>
    <div
        class="dark flex h-screen w-screen items-center justify-center overflow-hidden bg-transparent text-foreground"
    >
        <Transition
            mode="out-in"
            enter-active-class="motion-safe:animate-in motion-safe:fade-in motion-safe:duration-700"
            leave-active-class="motion-safe:animate-out motion-safe:fade-out motion-safe:duration-500"
        >
            <slot />
        </Transition>
    </div>
</template>
