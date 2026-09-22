import { Puzzle } from '@lucide/vue';
import { describe, expect, it } from 'vitest';
import { iconFor } from './navIcons';

/**
 * #320: collapsed, the console's sidebar shows nothing but icons, so each
 * entry must be recognisable by its icon alone. Two rules pin that: every
 * console entry names an icon `navIcons.ts` knows (an unknown name falls
 * back to the same puzzle piece for all of them), and no two console
 * entries share one.
 *
 * The entries are PHP (`NavigationItem`s in each module's provider,
 * ADR-0003), read here as text through `import.meta.glob` the way
 * `moduleImports.test.ts` reads Vue files.
 */
const providers = import.meta.glob<string>(
    '/app-modules/*/src/Providers/*.php',
    { query: '?raw', import: 'default', eager: true },
);

type ConsoleEntry = { module: string; label: string; icon: string | null };

function consoleEntries(): ConsoleEntry[] {
    const entries: ConsoleEntry[] = [];

    for (const source of Object.values(providers)) {
        for (const match of source.matchAll(
            /new NavigationItem\(([\s\S]*?)\n\s*\)/g,
        )) {
            const body = match[1];

            if (!body.includes('NavigationItem::AREA_ADMIN')) {
                continue;
            }

            entries.push({
                module: /module:\s*'([^']+)'/.exec(body)?.[1] ?? '?',
                label: /label:\s*'([^']+)'/.exec(body)?.[1] ?? '?',
                icon: /icon:\s*'([^']+)'/.exec(body)?.[1] ?? null,
            });
        }
    }

    return entries;
}

describe('console navigation icons', () => {
    const entries = consoleEntries();

    it('finds the console entries', () => {
        expect(entries.length).toBeGreaterThan(20);
    });

    it('gives every console entry an icon the map knows', () => {
        const unknown = entries
            .filter((entry) => iconFor(entry.icon) === Puzzle)
            .map((entry) => `${entry.module}: ${entry.label} (${entry.icon})`);

        expect(unknown).toEqual([]);
    });

    it('gives no two console entries the same icon', () => {
        const byIcon = new Map<string, string[]>();

        for (const entry of entries) {
            const key = entry.icon ?? '(none)';
            byIcon.set(key, [...(byIcon.get(key) ?? []), entry.label]);
        }

        const shared = [...byIcon.entries()]
            .filter(([, labels]) => labels.length > 1)
            .map(([icon, labels]) => `${icon}: ${labels.join(', ')}`);

        expect(shared).toEqual([]);
    });
});
