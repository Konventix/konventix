import inertia from '@inertiajs/vite';
import { wayfinder } from '@laravel/vite-plugin-wayfinder';
import tailwindcss from '@tailwindcss/vite';
import vue from '@vitejs/plugin-vue';
import laravel from 'laravel-vite-plugin';
import { bunny } from 'laravel-vite-plugin/fonts';
import { defineConfig } from 'vite';
import { VitePWA } from 'vite-plugin-pwa';

export default defineConfig({
    plugins: [
        laravel({
            // The second entry is the offline entrance client (ADR-0002's one
            // sanctioned runtime carve-out, ADR-0033's trust model). It is a
            // separate bundle rather than an Inertia page because it has to
            // boot and decide with no network at all — an Inertia page needs a
            // server response before it can render anything.
            input: ['resources/css/app.css', 'resources/js/app.ts', 'resources/js/entrance/entrance-client.ts'],
            refresh: true,
            fonts: [
                bunny('Instrument Sans', {
                    weights: [400, 500, 600],
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
        // Precaches the entrance client's own shell so a scanner that is
        // power-cycled at a venue with no uplink still boots. Deliberately
        // scoped to /entrance: the rest of the app is online-only by design,
        // and precaching it would ship megabytes of admin UI to a door tablet.
        VitePWA({
            registerType: 'autoUpdate',
            injectRegister: null,
            filename: 'entrance-sw.js',
            manifestFilename: 'entrance.webmanifest',
            scope: '/entrance',
            base: '/',
            manifest: {
                name: 'Konventix Entrance',
                short_name: 'Entrance',
                start_url: '/entrance',
                scope: '/entrance',
                display: 'fullscreen',
                orientation: 'portrait',
                background_color: '#111827',
                theme_color: '#111827',
            },
            workbox: {
                globDirectory: 'public',
                // Only this client's own bundle. Its styles are scoped inside
                // the SFC, so it needs none of the app's Tailwind CSS — and a
                // door tablet should not be made to carry the admin UI.
                globPatterns: ['build/assets/entrance-client*.{js,css}', 'build/assets/app-*.css'],
                runtimeCaching: [
                    {
                        // The shell is a Laravel view, not a static file, so it
                        // cannot be precached at build time. NetworkFirst caches
                        // it on the first online visit and serves that copy when
                        // the uplink is gone — without this the PWA has a bundle
                        // it can never reach, which defeats the whole carve-out.
                        urlPattern: ({ url }: { url: URL }) => url.pathname === '/entrance',
                        handler: 'NetworkFirst',
                        options: {
                            cacheName: 'entrance-shell',
                            networkTimeoutSeconds: 3,
                        },
                    },
                ],
                // The API is deliberately never cached, at build time or at
                // runtime: stale keys or a stale delta must come from
                // IndexedDB, where the client knows how old they are and can
                // say so, not from an HTTP cache that silently looks fresh.
                navigateFallbackDenylist: [/^\/api\//],
            },
        }),
    ],
});
