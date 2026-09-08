import { createInertiaApp } from '@inertiajs/vue3';
import { configureEcho } from '@laravel/echo-vue';
import type { DefineComponent } from 'vue';
import { initializeTheme } from '@/composables/useAppearance';
import AdminLayout from '@/layouts/AdminLayout.vue';
import AppTopbarLayout from '@/layouts/AppTopbarLayout.vue';
import AuthLayout from '@/layouts/AuthLayout.vue';
import SettingsLayout from '@/layouts/settings/Layout.vue';
import { configureDateTime } from '@/lib/datetime';
import type { DateFormat, TimeFormat } from '@/lib/datetime';
import { initializeFlashToast } from '@/lib/flashToast';
import { resolveReverbEndpoint } from '@/lib/reverbEndpoint';

/**
 * The Reverb app key is per brand (ADR-0025), so it rides in on the Inertia
 * page props embedded in the root element rather than a build-time VITE
 * constant. Host, port and scheme are resolved by `resolveReverbEndpoint`
 * (ADR-0049 §5): dev keeps the build-time VITE values, production derives
 * them from the page itself.
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
    // SSR never opens a websocket; the placeholder location is inert there.
    ...resolveReverbEndpoint(
        typeof window === 'undefined'
            ? { hostname: 'localhost', protocol: 'http:', port: '' }
            : window.location,
        {
            host: import.meta.env.VITE_REVERB_HOST,
            port: import.meta.env.VITE_REVERB_PORT,
            scheme: import.meta.env.VITE_REVERB_SCHEME,
        },
    ),
});

/**
 * The document-title suffix is the resolved brand's name, not the
 * deployment's (ADR-0001) — a visitor on a brand host must never read the
 * operator's application name in their browser tab.
 *
 * `resolve()` is the earliest hook that sees the page, and it runs before
 * the head is rendered, on the server for the initial response and again on
 * every client visit. It is assigned synchronously, before the page chunk is
 * awaited, because `createInertiaApp` runs once per SSR *process* rather
 * than once per request: two brands rendering concurrently share this
 * module. Narrowing the window to zero would mean Inertia handing the page
 * to the title callback, which it does not do — so the fallback below is the
 * only thing at stake, and it is only ever another brand of the same
 * operator.
 */
let documentTitleSuffix = import.meta.env.VITE_APP_NAME || 'Konventix';

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

/**
 * Two shells, and a page belongs to exactly one of them: the operator
 * console keeps its sidebar (ADR-0029), every brand-facing page — landing
 * page, static pages, member pages alike — wears the one topbar. Auth pages
 * keep their own bare shell, since a login form has nothing to navigate.
 *
 * Handed to Inertia as the *default* layout rather than assigned onto the
 * resolved page component. The distinction is not cosmetic: a page that
 * declares layout props — `defineOptions({ layout: { breadcrumbs } })`,
 * which every auth and settings page does — has a `layout` that is neither
 * undefined nor a component, so assigning "only when undefined" skipped all
 * of them and they rendered with no shell at all. As a default, Inertia
 * merges those props into the layout it picks here, which is what the
 * option is for.
 *
 * A page still overrides this by naming a component (see settings/Index),
 * and only by naming one: an explicit `layout: null` falls through to this
 * default, so opting out of the shell entirely is no longer expressible —
 * nothing needs it, since the bare-page cases are auth pages, which have a
 * shell of their own.
 */
function layoutFor(name: string) {
    switch (true) {
        case name.startsWith('admin/'):
            return AdminLayout;
        case name.startsWith('auth/'):
            return AuthLayout;
        case name.startsWith('settings/'):
            return [AppTopbarLayout, SettingsLayout];
        default:
            return AppTopbarLayout;
    }
}

/**
 * The viewing person's own time settings, out of the page props (#243, #242):
 * their profile zone, and the date and time conventions the operator sells
 * under.
 *
 * Applied in `resolve()`, after the page chunk has been awaited and with
 * nothing asynchronous between that and handing the component back. The order
 * matters under SSR: `settings` is module state shared by every request the
 * render process serves, so assigning it *before* an `await` is an
 * interleaving point — request A assigns, yields at the import, request B
 * assigns, and A renders with B's zone. Assigning after the await closes that
 * window (review finding 8).
 *
 * A page that had to ask for the zone itself is a page that could forget to,
 * which is why this is not a per-page prop.
 */
function applyDateTimeSettings(props: unknown): void {
    const page = props as
        | {
              auth?: { user?: { timezone?: string | null } | null };
              dateTimeFormat?: { date?: DateFormat; time?: TimeFormat };
          }
        | undefined;

    configureDateTime({
        timezone: page?.auth?.user?.timezone ?? null,
        dateFormat: page?.dateTimeFormat?.date ?? 'DD.MM.YYYY',
        timeFormat: page?.dateTimeFormat?.time ?? '24h',
    });
}

createInertiaApp({
    title: (title) =>
        title ? `${title} - ${documentTitleSuffix}` : documentTitleSuffix,
    resolve: async (name, visit) => {
        const brandName = visit?.props?.name;

        if (typeof brandName === 'string' && brandName !== '') {
            documentTitleSuffix = brandName;
        }

        const loader = pageLoaders.get(name);

        if (!loader) {
            throw new Error(`Inertia page not found: ${name}`);
        }

        const component = await loader();

        // Last, with no await after it: see `applyDateTimeSettings`.
        applyDateTimeSettings(visit?.props);

        return component;
    },
    layout: (name: string) => layoutFor(name),
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
