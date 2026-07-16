import { computed, onUnmounted, ref } from 'vue';
import type { ComputedRef } from 'vue';
import type { ScenePayloadDto } from '@/types';

export interface UseSceneRotationOptions {
    /**
     * Neutral scene shown when `scenes` is empty (no rotation to run). Not
     * pushed through `override()` — it is a pure fallback, not part of the
     * rotation list.
     */
    idle: ScenePayloadDto;
}

export interface UseSceneRotation {
    /** The scene currently on screen — either from the rotation or a pushed override. */
    current: ComputedRef<ScenePayloadDto>;
    /** Interrupts the rotation with `scene` for `ms` milliseconds, then resumes. */
    override: (scene: ScenePayloadDto, ms: number) => void;
}

/**
 * Owns the "which scene is on screen right now" state for the public
 * beamer page: advances through `scenes` in order, each for its own
 * `durationSec`, looping back to the start; `override()` lets a
 * `scene.override` broadcast interrupt with a scene shown for a fixed
 * duration before rotation resumes where it left off.
 */
export function useSceneRotation(
    scenes: ComputedRef<ScenePayloadDto[]> | ScenePayloadDto[],
    options: UseSceneRotationOptions,
): UseSceneRotation {
    const scenesRef = computed(() =>
        Array.isArray(scenes) ? scenes : scenes.value,
    );

    const index = ref(0);
    const overrideScene = ref<ScenePayloadDto | null>(null);

    let rotationTimer: ReturnType<typeof setTimeout> | null = null;
    let overrideTimer: ReturnType<typeof setTimeout> | null = null;

    function clearRotationTimer(): void {
        if (rotationTimer !== null) {
            clearTimeout(rotationTimer);
            rotationTimer = null;
        }
    }

    function clearOverrideTimer(): void {
        if (overrideTimer !== null) {
            clearTimeout(overrideTimer);
            overrideTimer = null;
        }
    }

    function scheduleNext(): void {
        clearRotationTimer();

        const list = scenesRef.value;

        if (list.length === 0) {
            return;
        }

        const active = list[index.value % list.length];
        const durationMs = Math.max(active.durationSec, 1) * 1000;

        rotationTimer = setTimeout(() => {
            index.value = (index.value + 1) % list.length;
            scheduleNext();
        }, durationMs);
    }

    scheduleNext();

    const current = computed<ScenePayloadDto>(() => {
        if (overrideScene.value !== null) {
            return overrideScene.value;
        }

        const list = scenesRef.value;

        if (list.length === 0) {
            return options.idle;
        }

        return list[index.value % list.length];
    });

    function override(scene: ScenePayloadDto, ms: number): void {
        clearOverrideTimer();
        overrideScene.value = scene;

        overrideTimer = setTimeout(() => {
            overrideScene.value = null;
            clearOverrideTimer();
        }, ms);
    }

    onUnmounted(() => {
        clearRotationTimer();
        clearOverrideTimer();
    });

    return { current, override };
}
