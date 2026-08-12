import { createInertiaApp } from '@inertiajs/vue3';
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

createInertiaApp({
    title: (title) => (title ? `${title} - ${appName}` : appName),
    layout: (name) => {
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
