import { getActiveLanguage, I18n, i18nVue } from 'laravel-vue-i18n';
import type { App } from 'vue';

/**
 * ADR-0068: the catalogs PHP reads are the catalogs Vue reads. Every
 * `lang/<locale>.json` at the root and under `app-modules/<module>/lang/`
 * is one map at runtime; a variant locale (`de_formal`) overlays its base.
 *
 * The chain comes from the server (`localeChain`, e.g. `['de_formal',
 * 'de', 'en']`) so the variant table lives in `config/app.php` only.
 */
export type Catalog = Record<string, string>;

const SOURCE_LOCALE = 'en';

/**
 * Pure: pick every file whose basename is `<locale>.json` for each locale
 * in the chain, base first, root before modules, later files winning.
 */
export function catalogFor(
    chain: string[],
    files: Record<string, Catalog>,
): Catalog {
    const merged: Catalog = {};
    const locales = [...chain]
        .filter((locale) => locale !== SOURCE_LOCALE)
        .reverse();

    for (const locale of locales) {
        const paths = Object.keys(files)
            .filter((path) => path.endsWith(`/${locale}.json`))
            .sort(
                (a, b) =>
                    Number(a.includes('/app-modules/')) -
                        Number(b.includes('/app-modules/')) ||
                    a.localeCompare(b),
            );

        for (const path of paths) {
            Object.assign(merged, files[path]);
        }
    }

    return merged;
}

/**
 * The part of the chain the plugin's `resolve(lang)` is asking about. The
 * plugin caches each answer by `lang` in static state (`I18n.loaded`) that
 * outlives the request under SSR, so `resolve('en')` must answer English —
 * not the first request's German — and a `php_<lang>` probe answers nothing.
 */
export function chainFrom(chain: string[], lang: string): string[] {
    const index = chain.indexOf(lang);

    return index === -1 ? [lang] : chain.slice(index);
}

type Loaded = { default: Catalog };

// Lazy on both sides. An eager glob behind `import.meta.env.SSR` still
// lands every catalog in the client entry chunk: the glob's static imports
// survive the dead branch because the same files are dynamic imports too.
const catalogFiles = import.meta.glob<Loaded>([
    '/lang/*.json',
    '/app-modules/*/lang/*.json',
]);

/**
 * The catalog files fetched so far, by path. Shared by every request an SSR
 * process serves, which is safe: it only ever holds file contents.
 */
const loadedFiles: Record<string, Catalog> = {};

/**
 * Fetch the chain's catalog files. `app.ts` awaits this in the page
 * `resolve`, before Inertia creates the app, so `installI18n` finds them
 * loaded and the plugin's `resolve` can answer synchronously — which SSR
 * requires, and which the client needs to hydrate in the server's language:
 * `mount()` follows `withApp` synchronously, while the plugin applies even a
 * synchronous answer a microtask late in the browser.
 *
 * Answers whether every file arrived. A chunk that fails to load does not
 * reject the visit into a blank page: the caller keeps the current language
 * and does not `followLocale` into a chain it has no catalog for, so a later
 * visit that loads it still switches.
 */
export async function loadCatalogs(
    chain: string[],
    importers: Record<string, () => Promise<Loaded>> = catalogFiles,
): Promise<boolean> {
    const wanted = Object.entries(importers).filter(
        ([path]) =>
            !(path in loadedFiles) &&
            chain.some((locale) => path.endsWith(`/${locale}.json`)),
    );

    try {
        const loaded = await Promise.all(
            wanted.map(
                async ([path, load]) => [path, (await load()).default] as const,
            ),
        );

        for (const [path, catalog] of loaded) {
            loadedFiles[path] = catalog;
        }
    } catch {
        return false;
    }

    return true;
}

/**
 * The chain the plugin's `resolve` answers from. It is not captured at
 * install: a client-side visit may carry another chain (ADR-0068 §4, a
 * locale saved in the profile), and a captured `de_formal` chain would drop
 * the `de` base when the plugin later resolves on its own. Under SSR it is
 * set per request in `installI18n`, synchronously before the render.
 */
let currentChain: string[] = [SOURCE_LOCALE];

/** Browser only: whether `installI18n` has run, so `followLocale` may. */
let installedInBrowser = false;

/**
 * Installed from `createInertiaApp`'s `withApp` hook, once per Vue app.
 * The plugin's shared I18n instance is module state under SSR, exactly
 * like `configureDateTime` in app.ts: it is set here, synchronously, with
 * nothing awaited between this and the render.
 */
export function installI18n(
    app: App,
    options: { chain: string[]; ssr: boolean },
): void {
    const { chain, ssr } = options;
    const lang = chain[0] ?? SOURCE_LOCALE;

    currentChain = chain;

    app.use(i18nVue, {
        lang,
        fallbackLang: SOURCE_LOCALE,
        fallbackMissingTranslations: true,
        resolve: (requested: string) =>
            catalogFor(chainFrom(currentChain, requested), loadedFiles),
    });

    if (!ssr) {
        installedInBrowser = true;
        // Hydrate with the messages now; the plugin's own load applies the
        // same ones a microtask later.
        setMessages(chain, loadedFiles);
    }
}

/**
 * Switch the active language to the page's chain on a client-side visit.
 * `app.ts` calls it from the page `resolve`, after `loadCatalogs` for the
 * same chain succeeded and before Inertia swaps the page in — the one hook
 * every swap passes through; the `navigate` event skips a redirect back to
 * the same URL, which is what saving a locale in the profile is. A no-op
 * before the app is installed (the first page) and when the page is already
 * in that language. The comparison is against the language the plugin
 * actually shows, not a remembered chain, so nothing a failed visit left
 * behind can make a later one look like "no change".
 */
export function followLocale(
    chain: string[],
    files: Record<string, Catalog> = loadedFiles,
): void {
    const lang = chain[0] ?? SOURCE_LOCALE;

    if (!installedInBrowser || lang === getActiveLanguage()) {
        return;
    }

    setMessages(chain, files);
    currentChain = chain;
}

/**
 * `I18n#setLanguage` is the plugin's low-level setter, not its documented
 * API: it replaces the active messages, sets `<html lang>` in the browser,
 * and is synchronous, which neither `loadLanguageAsync` nor the plugin's own
 * client load is. Written against laravel-vue-i18n 2.9
 * (`node_modules/laravel-vue-i18n/dist/index.mjs:249`) — check it there on
 * a version bump.
 */
function setMessages(chain: string[], files: Record<string, Catalog>): void {
    I18n.getSharedInstance().setLanguage({
        lang: chain[0] ?? SOURCE_LOCALE,
        messages: catalogFor(chain, files),
    });
}
