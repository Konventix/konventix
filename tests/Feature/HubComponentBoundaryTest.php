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
 * generated Wayfinder helpers (`@/routes/<own module>`,
 * `@/actions/Konventix/<OwnModule>/…`, and the host-level `@/routes/admin/…`
 * family, which names no module), and anything beside it inside its own
 * module.
 *
 * What it may not import: another module's routes, another module's actions,
 * another module's components — by alias or by climbing out with `../`.
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
                $owner = match (true) {
                    // `@/actions/Konventix/<Module>/Http/Controllers/…`
                    preg_match('#^@/actions/Konventix/([^/]+)#', $import, $matches) === 1 => $normalise($matches[1]),
                    // `@/routes/<name>/…` — a module only when the name is one.
                    preg_match('#^@/routes/([^/]+)#', $import, $matches) === 1 && in_array($normalise($matches[1]), $moduleNames, true) => $normalise($matches[1]),
                    // An absolute or aliased path straight into a module tree.
                    preg_match('#app-modules/([^/]+)/#', $import, $matches) === 1 => $normalise($matches[1]),
                    // `./x`, `../x` — a violation only once it leaves the module.
                    str_starts_with($import, '.') => str_starts_with($resolve($file->getPath(), $import), base_path("app-modules/{$module}/"))
                        ? $normalise($module)
                        : 'outside the module',
                    default => null,
                };

                if ($owner !== null && $owner !== $normalise($module)) {
                    $violations[] = "{$relative}: {$import}";
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
