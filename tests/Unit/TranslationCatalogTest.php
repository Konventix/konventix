<?php

declare(strict_types=1);

use Symfony\Component\Finder\Finder;

/*
|--------------------------------------------------------------------------
| One catalog per module, and every catalog complete (ADR-0068 §9)
|--------------------------------------------------------------------------
|
| A key the source uses must exist in a shipped catalog, a key no source uses
| must not linger, and one English sentence must not carry two Germans across
| modules. A grep is blunt; blunt is what keeps a sweep swept (see
| DateTimeRenderingTest).
|
| What counts as "the source uses a key":
|
| - a literal first argument to `__`, `trans`, `trans_choice`, `wTrans`,
|   `transChoice`, `wTransChoice`, `$t` or `$tChoice` in any PHP, Blade, Vue
|   or TS file — template attributes included (`:label="$t('Save')"`), and
|   the offline entrance client's own `t()` under resources/js/entrance;
| - for the stale check only, a registered label: any string literal in a
|   module's PHP source (`app-modules/<module>/src`), in the shell's
|   `resources/js/lib` helpers (tests excluded) and in AdminSidebar.vue, and
|   a static `title="…"` / `description="…"` attribute in a Vue template
|   (plus the framework's own error views, which call `__('Forbidden')` &
|   co.). Navigation items, hub cards, block types, token catalogs, settings
|   sections, cookie declarations and exception messages hand an English
|   label on, and a page renders it as `$t(item.title)` or `__($label)` — a
|   dynamic key no call-site grep can follow. A stale check that cannot see
|   those would delete real translations, so a catalog key counts as used
|   when one of those sources spells it as a literal (a literal split with
|   `.` / `+` across lines is rejoined first), or when it is an admin
|   sidebar group label derived from a module directory name. Any other
|   literal — a Vue page's prop default, a test fixture — does not keep a
|   line alive. This makes the stale check lenient, never the missing-key
|   check.
|
| Laravel's own group files (lang/<locale>/auth.php, …) and package
| namespaces (`pkg::key`) are not JSON catalogs and are skipped.
*/

function catalogTestRoot(string $to = ''): string
{
    return rtrim(dirname(__DIR__, 2).'/'.$to, '/');
}

/** @return array<string, array<string, string>> path => catalog */
function catalogTestCatalogs(string $locale): array
{
    $files = [
        ...(glob(catalogTestRoot("lang/{$locale}.json")) ?: []),
        ...(glob(catalogTestRoot("app-modules/*/lang/{$locale}.json")) ?: []),
    ];
    $out = [];

    foreach ($files as $file) {
        $out[substr($file, strlen(catalogTestRoot()) + 1)] = json_decode((string) file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
    }

    return $out;
}

/** @return Finder the PHP, Vue and TS sources that may speak */
function catalogTestSources(): Finder
{
    return (new Finder)->files()
        ->in([catalogTestRoot('app'), catalogTestRoot('app-modules'), catalogTestRoot('resources')])
        ->exclude(['node_modules', 'vendor', 'tests', 'lang'])
        // Wayfinder's generated TS, by path: a module's own `routes/` or
        // `src/Actions/` is source, not generated output.
        ->notPath(['#^js/(?:wayfinder|actions|routes)/#'])
        ->name(['*.php', '*.vue', '*.ts']);
}

/** Laravel group-file keys (`auth.failed`) and package keys (`pkg::key`). */
function catalogTestIsNonCatalogKey(string $key): bool
{
    if (str_contains($key, '::')) {
        return true;
    }

    if (preg_match('/^([a-z_]+)\.[a-z_.]+$/', $key, $m)) {
        return is_file(catalogTestRoot("lang/en/{$m[1]}.php")) || is_file(catalogTestRoot("lang/de/{$m[1]}.php"));
    }

    return false;
}

/** @return array<string, true> every literal key passed to a translation call */
function catalogTestUsedKeys(): array
{
    static $cache = null;

    if ($cache !== null) {
        return $cache;
    }

    $functions = '__|trans|wTrans|transChoice|wTransChoice|trans_choice|\$t|\$tChoice';
    $literal = '([\'"])((?:\\\\.|(?!\1).)*)\1';
    $pattern = "/(?<![\\w$])(?:{$functions})\\(\\s*{$literal}/";
    $entrance = "/(?<![\\w$.])(?:{$functions}|t)\\(\\s*{$literal}/";
    $keys = [];

    foreach (catalogTestSources() as $file) {
        $regex = str_contains($file->getPathname(), '/resources/js/entrance/') ? $entrance : $pattern;

        if (preg_match_all($regex, $file->getContents(), $m)) {
            foreach ($m[2] as $key) {
                $key = stripcslashes($key);

                if (! catalogTestIsNonCatalogKey($key)) {
                    $keys[$key] = true;
                }
            }
        }
    }

    return $cache = $keys;
}

/**
 * The literals a registered label can come from (see header), plus the admin
 * sidebar's module group labels: every string literal in a module's PHP
 * source, in the shell's `resources/js/lib` helpers and in AdminSidebar.vue,
 * and a Vue template's static `title="…"` / `description="…"` attribute,
 * which the component renders through `$t()`. The framework's own error
 * views call `__('Forbidden')` & co. No other literal counts.
 *
 * @return array<string, true>
 */
function catalogTestSourceLiterals(): array
{
    static $cache = null;

    if ($cache !== null) {
        return $cache;
    }

    $literals = [];
    $sources = [
        ...iterator_to_array((new Finder)->files()->in(catalogTestRoot('app-modules'))->path('#^[^/]+/src/#')->name('*.php'), false),
        ...iterator_to_array((new Finder)->files()->in(catalogTestRoot('resources/js/lib'))->name(['*.ts', '*.vue'])->notName('*.test.ts'), false),
        new SplFileInfo(catalogTestRoot('resources/js/components/admin/AdminSidebar.vue')),
        ...iterator_to_array((new Finder)->files()->in(catalogTestRoot('vendor/laravel/framework/src/Illuminate/Foundation/Exceptions/views'))->name('*.php'), false),
    ];

    foreach ($sources as $file) {
        // Rejoin a sentence split across lines: 'a '."\n".'. b' and 'a ' + 'b'.
        $text = preg_replace('/([\'"])\s*[.+]\s*\1/', '', (string) file_get_contents($file->getPathname())) ?? '';

        // One line at a time, so a stray apostrophe in a comment or prose
        // cannot pair with a quote further down and invert the file.
        if (preg_match_all('/\'((?:\\\\.|[^\'\\\\\n])*)\'|"((?:\\\\.|[^"\\\\\n])*)"|`((?:\\\\.|[^`\\\\$\n])*)`/', $text, $m)) {
            foreach (array_keys($m[0]) as $i) {
                $literals[stripcslashes($m[1][$i].$m[2][$i].$m[3][$i])] = true;
            }
        }
    }

    // Static (unbound) title/description attributes in any Vue template, and
    // the same pair a page hands its layout in `defineOptions({ layout })`,
    // which AuthLayout renders through `$t()`.
    foreach (catalogTestSources()->name('*.vue') as $file) {
        $contents = $file->getContents();
        preg_match_all('/(?<![:\w.-])(?:title|description)="([^"]+)"/', $contents, $attributes);
        preg_match('/defineOptions\(\{.*?\}\);/s', $contents, $options);
        preg_match_all('/\b(?:title|description):\s*\'((?:\\\\.|[^\'\\\\\n])*)\'/', $options[0] ?? '', $layout);

        foreach ([...$attributes[1], ...$layout[1]] as $label) {
            $literals[stripcslashes($label)] = true;
        }
    }

    // AdminSidebar.vue's humanizeModule(): 'game-ops' -> 'Game Ops'.
    foreach (glob(catalogTestRoot('app-modules/*'), GLOB_ONLYDIR) ?: [] as $module) {
        $literals[ucwords(str_replace('-', ' ', basename($module)))] = true;
    }

    return $cache = $literals;
}

it('has a german line for every key the source uses', function (): void {
    $known = array_merge(...array_values(catalogTestCatalogs('de')));
    $missing = array_diff_key(catalogTestUsedKeys(), $known);

    expect(array_keys($missing))->toBe([]);
});

it('keeps no german line the source no longer uses', function (): void {
    $used = catalogTestUsedKeys() + catalogTestSourceLiterals();
    $stale = [];

    foreach (catalogTestCatalogs('de') as $path => $catalog) {
        foreach (array_diff_key($catalog, $used) as $key => $_) {
            $stale[] = "{$path}: {$key}";
        }
    }

    expect($stale)->toBe([]);
});

it('gives one english sentence one german across modules', function (): void {
    $seen = [];
    $conflicts = [];

    foreach (catalogTestCatalogs('de') as $path => $catalog) {
        foreach ($catalog as $key => $value) {
            if (isset($seen[$key]) && $seen[$key][1] !== $value) {
                $conflicts[] = "{$key}: {$seen[$key][0]} says '{$seen[$key][1]}', {$path} says '{$value}'";
            }

            $seen[$key] = [$path, $value];
        }
    }

    expect($conflicts)->toBe([]);
});

/*
| Words German uses as they are. A catalog line whose value equals its key
| passes only when every word in it (placeholders and plural syntax
| stripped) is on this list — "Ticket :reference" and "Teams (:count)" pass,
| "Secret Key" does not. A hyphenated word is one word: "Check-in" matches
| `check-in`, never `check` and `in`. Add a word here only when a German
| reader would write it the same way and no German word says it ("Desk" is
| a product term); everything else gets translated. A word no catalog line
| needs any more comes off the list.
*/
const CATALOG_TEST_GERMAN_AS_IS = [
    'agenda', 'avatar', 'badge', 'banner', 'brand', 'brands', 'browser', 'bucket', 'check-in', 'code', 'community',
    'compliance', 'countdown', 'desk', 'details', 'e-mail', 'favicon', 'feedback', 'host', 'linear', 'link', 'live',
    'markdown', 'mm', 'name', 'news', 'newsletter', 'normal', 'offline', 'online', 'optional', 'passkeys',
    'payload', 'port', 'quiz', 'radius', 'region', 'shop', 'shoutbox', 'sku', 'slug', 'sponsoring', 'status',
    'support', 'symbol', 'system', 'tabletop', 'team', 'teams', 'text', 'ticket', 'tickets', 'token', 'track',
    'tracks', 'url', 'version', 'viewport', 'website', 'x', 'y', 'zone',
];

it('never ships an english value as a german line', function (): void {
    $untranslated = [];

    foreach (catalogTestCatalogs('de') as $path => $catalog) {
        foreach ($catalog as $key => $value) {
            if ($value !== $key) {
                continue;
            }

            $prose = preg_replace(['/:[a-z_]+/i', '/\{\d+\}|\[\d+,(?:\d+|\*)\]/'], ' ', $key) ?? '';
            $words = array_filter(array_map(
                static fn (string $word): string => trim($word, '-'),
                preg_split('/[^a-z-]+/i', strtolower($prose)) ?: [],
            ));

            if (array_diff($words, CATALOG_TEST_GERMAN_AS_IS) !== []) {
                $untranslated[] = "{$path}: {$key}";
            }
        }
    }

    expect($untranslated)->toBe([]);
});
