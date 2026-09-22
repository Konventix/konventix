<?php

declare(strict_types=1);

use Konventix\Platform\Hub\HubRegistry;
use Symfony\Component\Finder\Finder;

/**
 * A hub card belongs to its module (design §8, ADR-0003): a card is
 * registered by the module that owns the thing it shows, and it ships with
 * that module — so a brand with that module switched off simply has no card,
 * rather than a card that reaches into nothing. The PHP half of that line is
 * already held by the per-module arch tests (no cross-module `use`); this is
 * the Vue half, which no compiler catches — a component resolved by name
 * through `resolveRegisteredComponent()` imports whatever it likes and the
 * build stays green until the other module is gone.
 *
 * What a hub component may import: the host's shared frontend
 * (`@/components/*`, `@/lib/*` — `.ai/rules/js.md` *requires* `@/lib/datetime`
 * for anything with a timestamp in it — `@/composables/*`, `@/types/*`), the
 * ecosystem (`vue`, `@inertiajs/vue3`, `@lucide/vue`, …), its own module's
 * generated Wayfinder helpers, and anything beside it inside its own module.
 *
 * What it may not import: another module's routes, another module's actions,
 * another module's components — by alias or by climbing out with `../`.
 *
 * A Wayfinder route file is **not** named after the module that owns it —
 * `@/routes/entrance` is Admission's and `@/routes/venue` is Event's — so the
 * file itself is asked: every generated route file carries
 * `@see app-modules/<module>/src/…` for each route in it, and that is what
 * decides the owner. `resources/js/routes` is generated and gitignored, so in
 * a checkout that has not built yet the file is missing and the rule falls
 * back to matching the first path segment against a module directory name.
 * The fallback is weaker — it would let `@/routes/entrance` through — but it
 * never invents a violation, and the build every CI run performs restores the
 * strict rule. Actions need no file: `@/actions/Konventix/<Module>/…` names
 * its module in the path.
 */
it('keeps hub components inside their own module', function (): void {
    $modules = collect(glob(base_path('app-modules/*'), GLOB_ONLYDIR) ?: [])
        ->map(fn (string $directory): string => basename($directory))
        ->all();

    // `game-ops` on disk is `GameOps` in the action namespace.
    $normalise = static fn (string $name): string => str_replace('-', '', strtolower($name));
    $moduleNames = array_map($normalise, $modules);

    /** Resolve `../x` against a directory without touching the filesystem. */
    $resolve = static function (string $directory, string $import): string {
        $segments = [];

        foreach (explode('/', $directory.'/'.$import) as $segment) {
            match ($segment) {
                '', '.' => null,
                '..' => array_pop($segments),
                default => $segments[] = $segment,
            };
        }

        return '/'.implode('/', $segments);
    };

    /**
     * The modules a generated Wayfinder route import actually reaches, read
     * from the `@see app-modules/<module>/…` lines the generator writes.
     * Null when the file is not there to ask — see the note above.
     *
     * @return list<string>|null
     */
    $wayfinderOwners = static function (string $import) use ($normalise): ?array {
        $path = trim(substr($import, strlen('@/routes')), '/');

        foreach ([base_path("resources/js/routes/{$path}.ts"), base_path("resources/js/routes/{$path}/index.ts")] as $candidate) {
            if (! is_file($candidate)) {
                continue;
            }

            preg_match_all('#@see app-modules/([^/]+)/#', (string) file_get_contents($candidate), $seen);

            return array_values(array_unique(array_map($normalise, $seen[1])));
        }

        return null;
    };

    $scanned = [];
    $violations = [];

    foreach ($modules as $module) {
        $hub = base_path("app-modules/{$module}/resources/js/components/hub");

        if (! is_dir($hub)) {
            continue;
        }

        foreach (Finder::create()->files()->in($hub)->name('*.vue') as $file) {
            $relative = "app-modules/{$module}/resources/js/components/hub/".$file->getRelativePathname();
            $scanned[] = $relative;

            preg_match_all('#\bfrom\s+[\'"]([^\'"]+)[\'"]#', (string) $file->getContents(), $imports);

            foreach ($imports[1] as $import) {
                $owners = match (true) {
                    // `@/actions/Konventix/<Module>/Http/Controllers/…`
                    preg_match('#^@/actions/Konventix/([^/]+)#', $import, $matches) === 1 => [$normalise($matches[1])],
                    // `@/routes/…` — ask the generated file, then fall back.
                    $import === '@/routes' || str_starts_with($import, '@/routes/') => $wayfinderOwners($import)
                        ?? array_values(array_filter(
                            [$normalise(explode('/', substr($import, strlen('@/routes/')))[0] ?? '')],
                            fn (string $name): bool => in_array($name, $moduleNames, true),
                        )),
                    // An absolute or aliased path straight into a module tree.
                    preg_match('#app-modules/([^/]+)/#', $import, $matches) === 1 => [$normalise($matches[1])],
                    // `./x`, `../x` — a violation only once it leaves the module.
                    str_starts_with($import, '.') => str_starts_with($resolve($file->getPath(), $import), base_path("app-modules/{$module}/"))
                        ? []
                        : ['outside the module'],
                    default => [],
                };

                foreach ($owners as $owner) {
                    if ($owner !== $normalise($module)) {
                        $violations[] = "{$relative}: {$import} ({$owner})";

                        break;
                    }
                }
            }
        }
    }

    // Without this the rule above would pass by scanning nothing at all the
    // day somebody renames the directory the cards live in.
    expect($scanned)->not->toBeEmpty()
        ->and($violations)->toBe([]);
});

/**
 * The other half of the same boundary: the *name* a module registers has to
 * be its own, because `resolveRegisteredComponent()` maps
 * `<module>/<path>` onto `app-modules/<module>/resources/js/components/<path>.vue`
 * without asking who registered it — nothing but this test stops one module
 * from putting another module's component on the page, or from naming a
 * component that ships nowhere and silently renders as a gap.
 */
it('registers hub contributions under the contributing module', function (): void {
    $items = app(HubRegistry::class)->all();

    expect($items)->not->toBeEmpty();

    foreach ($items as $item) {
        $component = $item->component;

        // A `HubLink` with a URL renders as a plain anchor and ships no component.
        if ($component === null) {
            continue;
        }

        expect($component)->toStartWith($item->module.'/')
            ->and(base_path("app-modules/{$item->module}/resources/js/components/".substr($component, strlen($item->module) + 1).'.vue'))
            ->toBeFile();
    }
});
