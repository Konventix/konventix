import type { App } from 'vue';

/**
 * The door's words (ADR-0068), from Admission's own catalog only, with no
 * i18n library at all.
 *
 * Whatever this entry shares with the app entry Rollup splits into a chunk
 * of its own — one the service worker's precache (`entrance-client*`) does
 * not list, so a scanner booting with no uplink would come up without it.
 * That rules out `laravel-vue-i18n` and `@/lib/i18n` (the app imports both)
 * and leaves this: a lookup, a merge along the chain and `:name`
 * substitution, all inside the entrance bundle.
 *
 * `?raw` for the same reason: the app's lazy catalog glob imports the very
 * same JSON files, and a plain import here would make each of them a shared
 * chunk too. As raw text the catalog is inlined into this bundle.
 */
type Catalog = Record<string, string>;

const SOURCE_LOCALE = 'en';

const catalogFiles = import.meta.glob<string>(
    '/app-modules/admission/lang/*.json',
    { eager: true, query: '?raw', import: 'default' },
);

/** The merged catalog `t` reads; empty (English source) until installed. */
let messages: Catalog = {};

/**
 * The chain the Blade shell wrote into `data-locale-chain` (e.g.
 * `["de_formal","de","en"]`). A shell cached before the attribute existed,
 * or a malformed one, falls back to the English source.
 */
export function localeChainOf(element: HTMLElement | null): string[] {
    try {
        const chain: unknown = JSON.parse(element?.dataset.localeChain ?? '[]');

        if (
            Array.isArray(chain) &&
            chain.length > 0 &&
            chain.every((locale) => typeof locale === 'string')
        ) {
            return chain as string[];
        }
    } catch {
        // Fall through to the source language.
    }

    return [SOURCE_LOCALE];
}

/**
 * Merges the catalogs along the chain, base first, so a variant
 * (`de_formal`) overlays its base (`de`). The source locale has no file:
 * English is the key itself.
 */
export function catalogFor(
    chain: string[],
    files: Record<string, string> = catalogFiles,
): Catalog {
    const merged: Catalog = {};

    for (const locale of [...chain].reverse()) {
        const path = Object.keys(files).find((candidate) =>
            candidate.endsWith(`/${locale}.json`),
        );

        if (locale !== SOURCE_LOCALE && path !== undefined) {
            Object.assign(merged, JSON.parse(files[path]) as Catalog);
        }
    }

    return merged;
}

/**
 * The translation of `key`, or the key itself, with every `:name` replaced.
 * Longest names first, so `:count` never eats the start of `:counter`.
 */
export function t(
    key: string,
    replacements: Record<string, string> = {},
): string {
    let text = messages[key] ?? key;

    const names = Object.keys(replacements).sort((a, b) => b.length - a.length);

    for (const name of names) {
        text = text.split(`:${name}`).join(replacements[name]);
    }

    return text;
}

/**
 * Loads the chain's catalog and gives every entrance SFC `$t` — the same
 * name the app's templates use, typed by the app's own declaration.
 */
export function installI18n(app: App, chain: string[]): void {
    messages = catalogFor(chain);
    app.config.globalProperties.$t = t;
}
