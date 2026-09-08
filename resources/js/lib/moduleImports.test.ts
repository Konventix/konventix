import { describe, expect, it } from 'vitest';

/**
 * Modules talk through events, not through each other's files (ADR-0004) —
 * and that holds on the frontend too, where nothing else enforces it.
 *
 * `tests/Unit/ArchitectureTest.php` reads PHP namespaces, so a Vue component
 * reaching four directories up into another module's `resources/js` is
 * invisible to it: `/shop`'s registration card imported Commerce's
 * `purchaseTexts` that way once (#240 re-review N1) and nothing failed.
 * `.ai/rules/resources.md` states the rule this pins — "module-owned Vue
 * pages and components live in `app-modules/<name>/resources/js/…`; only
 * shared shell/UI stays in the root `resources/js/`" — so anything two
 * modules need belongs in `resources/js/`, reached through the `@` alias.
 *
 * Read through `import.meta.glob` rather than `node:fs`: the same mechanism
 * `lib/moduleComponents.ts` uses to find module components, and the one that
 * type-checks under this project's tsconfig.
 */
const sources = import.meta.glob<string>(
    '/app-modules/*/resources/js/**/*.{vue,ts}',
    { query: '?raw', import: 'default', eager: true },
);

/** Every `from '…'` / `import('…')` specifier in one file. */
function importSpecifiers(contents: string): string[] {
    return [...contents.matchAll(/from\s+'([^']+)'|import\('([^']+)'\)/g)].map(
        (match) => match[1] ?? match[2],
    );
}

/** `/app-modules/<module>/resources/js` for a path under it. */
function moduleRootOf(path: string): string | null {
    const match = /^(\/app-modules\/[^/]+\/resources\/js)\//.exec(path);

    return match === null ? null : match[1];
}

/** Node-free `join()`: enough for the `.`/`..` segments an import can carry. */
function resolveRelative(from: string, specifier: string): string {
    const segments = from.split('/').slice(0, -1);

    for (const segment of specifier.split('/')) {
        if (segment === '.' || segment === '') {
            continue;
        }

        if (segment === '..') {
            segments.pop();

            continue;
        }

        segments.push(segment);
    }

    return segments.join('/');
}

describe('module frontend boundaries', () => {
    it('never imports another module’s resources/js by relative path', () => {
        const violations: string[] = [];

        for (const [path, contents] of Object.entries(sources)) {
            const root = moduleRootOf(path);

            if (root === null) {
                continue;
            }

            for (const specifier of importSpecifiers(contents)) {
                if (!specifier.startsWith('.')) {
                    continue;
                }

                if (!resolveRelative(path, specifier).startsWith(`${root}/`)) {
                    violations.push(`${path} imports ${specifier}`);
                }
            }
        }

        expect(violations).toEqual([]);
    });

    it('sees the files it is meant to guard', () => {
        // A glob that silently matched nothing would make the case above
        // pass for the wrong reason.
        expect(Object.keys(sources).length).toBeGreaterThan(50);
    });
});
