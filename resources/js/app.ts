import { createInertiaApp } from '@inertiajs/vue3';
import type { DefineComponent } from 'vue';
import { configureEcho } from '@laravel/echo-vue';
import { initializeTheme } from '@/composables/useAppearance';
import AppLayout from '@/layouts/AppLayout.vue';
import AdminLayout from '@/layouts/AdminLayout.vue';
import AuthLayout from '@/layouts/AuthLayout.vue';
import SettingsLayout from '@/layouts/settings/Layout.vue';
import { initializeFlashToast } from '@/lib/flashToast';

/**
 * The Reverb app key is per brand (ADR-0025), so it rides in on the Inertia
 * page props embedded in the root element rather than a build-time VITE
 * constant. Host, port and scheme stay environment-static VITE values.
 */
function brandReverbKey(): string | undefined {
    if (typeof document === 'undefined') {
        return undefined; // SSR build; Echo only runs in the browser.
    }

    const page = document.getElementById('app')?.dataset.page;

    if (!page) {
        return undefined;
    }

    try {
        return JSON.parse(page).props?.reverb?.key ?? undefined;
    } catch {
        return undefined;
    }
}

configureEcho({
    broadcaster: 'reverb',
    key: brandReverbKey() ?? import.meta.env.VITE_REVERB_APP_KEY,
});

const appName = import.meta.env.VITE_APP_NAME || 'Konventix';

/**
 * Module-local Inertia page resolution (.ai/rules/resources.md): a page
 * name resolves against the host's `resources/js/pages` AND every module's
 * `app-modules/<name>/resources/js/pages`, by identical relative path — a
 * controller says `admin/orders/Index` regardless of which side ships the
 * file. Duplicate names across sources are a build mistake and throw in dev.
 */
const hostPages = import.meta.glob<DefineComponent>('./pages/**/*.vue');
const modulePages = import.meta.glob<DefineComponent>(
    '/app-modules/*/resources/js/pages/**/*.vue',
);

const pageLoaders = new Map<string, () => Promise<DefineComponent>>();

for (const [path, loader] of Object.entries(hostPages)) {
    pageLoaders.set(path.replace('./pages/', '').replace(/\.vue$/, ''), loader);
}

for (const [path, loader] of Object.entries(modulePages)) {
    const name = path
        .replace(/^\/app-modules\/[^/]+\/resources\/js\/pages\//, '')
        .replace(/\.vue$/, '');

    if (import.meta.env.DEV && pageLoaders.has(name)) {
        throw new Error(
            `Duplicate Inertia page name "${name}" — ${path} collides with another pages directory.`,
        );
    }

    pageLoaders.set(name, loader);
}

function layoutFor(name: string) {
    switch (true) {
        case name === 'Welcome':
        case name === 'StaticPage':
            return null;
        case name.startsWith('admin/'):
            return AdminLayout;
        case name.startsWith('auth/'):
            return AuthLayout;
        case name.startsWith('settings/'):
            return [AppLayout, SettingsLayout];
        default:
            return AppLayout;
    }
}

createInertiaApp({
    title: (title) => (title ? `${title} - ${appName}` : appName),
    resolve: async (name) => {
        const loader = pageLoaders.get(name);

        if (!loader) {
            throw new Error(`Inertia page not found: ${name}`);
        }

        const page = await loader();

        // A page that sets its own `layout` keeps it — including an explicit
        // `null` (no layout, e.g. public pages via defineOptions); only a
        // page saying nothing gets the name-based default.
        if (page.default.layout === undefined) {
            page.default.layout = layoutFor(name);
        }

        return page;
    },
    progress: {
        // Semantic token, not a palette value — the brand accent re-colors the
        // progress bar along with everything else (ADR-0008).
        color: 'var(--accent)',
    },
});

// This will set light / dark mode on page load...
initializeTheme();

// This will listen for flash toast data from the server...
initializeFlashToast();
