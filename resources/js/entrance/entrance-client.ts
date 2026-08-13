import { createApp } from 'vue';
import EntranceApp from './EntranceApp.vue';

/**
 * Entry point for the offline entrance client (ADR-0002's carve-out).
 *
 * Deliberately a plain Vue app, not an Inertia page: Inertia needs a server
 * response before it can render, and this client's entire reason to exist is
 * working when there is no server to respond. Everything it needs lives in
 * IndexedDB from the last successful sync.
 */
createApp(EntranceApp).mount('#entrance');

if ('serviceWorker' in navigator) {
    // Registered by hand rather than by the plugin's virtual module, so the
    // scope stays pinned to /entrance and a failure here degrades to "no
    // offline shell" rather than breaking the app.
    navigator.serviceWorker.register('/entrance-sw.js', { scope: '/entrance' }).catch(() => {
        // Intentionally silent: a door with no service worker still scans.
    });
}
