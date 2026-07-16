<script setup lang="ts">
import { onMounted, onUnmounted, ref } from 'vue';

// Self-contained canvas confetti — no external JS dependency (see Task 7
// brief: verified none is vendored, none added). A fixed set of particles
// falls from the top of the viewport with a little sideways drift and
// rotation, looping for as long as this component stays mounted (i.e. for
// the SceneOverride's durationSec, controlled by the parent SceneWinner).
//
// Respects `prefers-reduced-motion`: when the user has requested reduced
// motion, no canvas animation runs at all (SceneWinner still shows the
// static "WINNER" text/name without this overlay's movement).

const canvasRef = ref<HTMLCanvasElement | null>(null);

interface Particle {
    x: number;
    y: number;
    size: number;
    color: string;
    speedY: number;
    speedX: number;
    rotation: number;
    rotationSpeed: number;
}

// The confetti palette is the design system's chart hues (--chart-1..5:
// amber/green/blue/violet/rose — see docs/design.md's dark-mode token
// table), read live off this canvas element so it always resolves the
// beamer's forced-dark scope (SceneFrame's `.dark` wrapper) rather than
// whatever the viewer's own OS light/dark preference set on <html>. Falls
// back to the dark-mode hardcoded values if custom properties are
// unavailable (e.g. a non-browser test environment).
const fallbackColors = ['#ffb020', '#35c08a', '#5ea0e0', '#c98bdb', '#e5717a'];

function readChartColors(el: HTMLElement): string[] {
    if (typeof getComputedStyle !== 'function') {
        return fallbackColors;
    }

    const styles = getComputedStyle(el);
    const values = [1, 2, 3, 4, 5]
        .map((n) => styles.getPropertyValue(`--chart-${n}`).trim())
        .filter((value) => value.length > 0);

    return values.length > 0 ? values : fallbackColors;
}

let colors: string[] = fallbackColors;

let particles: Particle[] = [];
let animationFrame: number | null = null;
let resizeHandler: (() => void) | null = null;

function prefersReducedMotion(): boolean {
    return (
        typeof window !== 'undefined' &&
        typeof window.matchMedia === 'function' &&
        window.matchMedia('(prefers-reduced-motion: reduce)').matches
    );
}

function createParticle(canvas: HTMLCanvasElement): Particle {
    return {
        x: Math.random() * canvas.width,
        y: Math.random() * -canvas.height,
        size: 6 + Math.random() * 8,
        color: colors[Math.floor(Math.random() * colors.length)],
        speedY: 2 + Math.random() * 3,
        speedX: (Math.random() - 0.5) * 2,
        rotation: Math.random() * 360,
        rotationSpeed: (Math.random() - 0.5) * 8,
    };
}

function resizeCanvas(canvas: HTMLCanvasElement): void {
    canvas.width = window.innerWidth;
    canvas.height = window.innerHeight;
}

function step(canvas: HTMLCanvasElement, ctx: CanvasRenderingContext2D): void {
    ctx.clearRect(0, 0, canvas.width, canvas.height);

    for (const particle of particles) {
        particle.y += particle.speedY;
        particle.x += particle.speedX;
        particle.rotation += particle.rotationSpeed;

        if (particle.y > canvas.height + particle.size) {
            particle.y = -particle.size;
            particle.x = Math.random() * canvas.width;
        }

        ctx.save();
        ctx.translate(particle.x, particle.y);
        ctx.rotate((particle.rotation * Math.PI) / 180);
        ctx.fillStyle = particle.color;
        ctx.fillRect(
            -particle.size / 2,
            -particle.size / 4,
            particle.size,
            particle.size / 2,
        );
        ctx.restore();
    }

    animationFrame = requestAnimationFrame(() => step(canvas, ctx));
}

onMounted(() => {
    if (prefersReducedMotion()) {
        return;
    }

    const canvas = canvasRef.value;

    if (!canvas) {
        return;
    }

    const ctx = canvas.getContext('2d');

    if (!ctx) {
        return;
    }

    colors = readChartColors(canvas);
    resizeCanvas(canvas);
    particles = Array.from({ length: 150 }, () => createParticle(canvas));

    resizeHandler = () => resizeCanvas(canvas);
    window.addEventListener('resize', resizeHandler);

    animationFrame = requestAnimationFrame(() => step(canvas, ctx));
});

onUnmounted(() => {
    if (animationFrame !== null) {
        cancelAnimationFrame(animationFrame);
    }

    if (resizeHandler) {
        window.removeEventListener('resize', resizeHandler);
    }
});
</script>

<template>
    <canvas
        ref="canvasRef"
        class="pointer-events-none fixed inset-0 h-screen w-screen"
        aria-hidden="true"
    />
</template>
