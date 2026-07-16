<script setup lang="ts">
// Chrome wrapper around whatever scene component is currently on screen —
// full-viewport centering + a fade transition between scenes so the client
// rotation (or an override) never hard-cuts.
</script>

<template>
    <div
        class="flex h-screen w-screen items-center justify-center overflow-hidden bg-black text-white"
    >
        <Transition mode="out-in" name="fade">
            <slot />
        </Transition>
    </div>
</template>
