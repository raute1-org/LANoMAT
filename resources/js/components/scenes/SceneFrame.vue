<script setup lang="ts">
// Chrome wrapper around whatever scene component is currently on screen —
// full-viewport centering + a fade transition between scenes so the client
// rotation (or an override) never hard-cuts.
//
// The beamer is always the "loud" dark stage regardless of a viewer's
// OS/browser light-mode preference, so `dark` is forced on this subtree
// (scoping every `.dark` token below it) rather than relying on the
// document-level class the rest of the app toggles.
</script>

<template>
    <div
        class="dark flex h-screen w-screen items-center justify-center overflow-hidden bg-background text-foreground"
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
