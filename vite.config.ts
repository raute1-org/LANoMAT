import inertia from '@inertiajs/vite';
import { wayfinder } from '@laravel/vite-plugin-wayfinder';
import tailwindcss from '@tailwindcss/vite';
import vue from '@vitejs/plugin-vue';
import laravel from 'laravel-vite-plugin';
import { local } from 'laravel-vite-plugin/fonts';
import { defineConfig } from 'vite';

export default defineConfig({
    plugins: [
        laravel({
            input: ['resources/css/app.css', 'resources/js/app.ts'],
            refresh: true,
            // Self-hosted (resources/fonts, SIL OFL): the build needs no network, so it works on a
            // venue server without internet and on hosts that cannot reach a font CDN.
            fonts: [
                local('Space Grotesk', {
                    variants: [400, 500, 600, 700].map((weight) => ({
                        src: `resources/fonts/space-grotesk/space-grotesk-latin-${weight}-normal.woff2`,
                        weight: weight as 400 | 500 | 600 | 700,
                    })),
                }),
                local('JetBrains Mono', {
                    variants: [400, 500, 600].map((weight) => ({
                        src: `resources/fonts/jetbrains-mono/jetbrains-mono-latin-${weight}-normal.woff2`,
                        weight: weight as 400 | 500 | 600,
                    })),
                }),
            ],
        }),
        inertia(),
        tailwindcss(),
        vue({
            template: {
                transformAssetUrls: {
                    base: null,
                    includeAbsolute: false,
                },
            },
        }),
        wayfinder({
            formVariants: true,
        }),
    ],
});
