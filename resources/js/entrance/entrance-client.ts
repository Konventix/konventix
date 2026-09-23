import { createApp } from 'vue';
import EntranceApp from './EntranceApp.vue';
import { installI18n, localeChainOf } from './lib/i18n';

/**
 * Entry point for the offline entrance client (ADR-0002's carve-out).
 *
 * Deliberately a plain Vue app, not an Inertia page: Inertia needs a server
 * response before it can render, and this client's entire reason to exist is
 * working when there is no server to respond. Everything it needs lives in
 * IndexedDB from the last successful sync.
 *
 * Its words come from Admission's catalog along the locale chain the shell
 * was rendered with (ADR-0068) — the cached shell keeps the language it was
 * cached in, which is the language the device was set up in.
 */
const root = document.getElementById('entrance');
const app = createApp(EntranceApp);

installI18n(app, localeChainOf(root));
app.mount('#entrance');

if ('serviceWorker' in navigator) {
    // Registered by hand rather than by the plugin's virtual module, so the
    // scope stays pinned to /entrance and a failure here degrades to "no
    // offline shell" rather than breaking the app.
    navigator.serviceWorker
        .register('/entrance-sw.js', { scope: '/entrance' })
        .catch(() => {
            // Intentionally silent: a door with no service worker still scans.
        });
}
