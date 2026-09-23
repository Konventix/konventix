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
| - for the stale check only, any string literal in those sources (and in
|   the framework's own error views, which call `__('Forbidden')` & co.).
|   Navigation items, hub cards, block types, token catalogs, settings
|   sections, cookie declarations and exception messages hand an English
|   label on, and a page renders it as `$t(item.title)` or `__($label)` — a
|   dynamic key no call-site grep can follow. A stale check that cannot see
|   those would delete real translations, so a catalog key counts as used
|   when the sources spell it anywhere as a literal (a literal split with
|   `.` / `+` across lines is rejoined first), or when it is an admin
|   sidebar group label derived from a module directory name. This makes
|   the stale check lenient, never the missing-key check.
|
| Laravel's own group files (lang/<locale>/auth.php, …) and package
| namespaces (`pkg::key`) are not JSON catalogs and are skipped.
*/

function catalogRoot(string $to = ''): string
{
    return rtrim(dirname(__DIR__, 2).'/'.$to, '/');
}

/** @return array<string, array<string, string>> path => catalog */
function catalogs(string $locale): array
{
    $files = [
        ...(glob(catalogRoot("lang/{$locale}.json")) ?: []),
        ...(glob(catalogRoot("app-modules/*/lang/{$locale}.json")) ?: []),
    ];
    $out = [];

    foreach ($files as $file) {
        $out[substr($file, strlen(catalogRoot()) + 1)] = json_decode((string) file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
    }

    return $out;
}

/** @return Finder the PHP, Vue and TS sources that may speak */
function catalogSources(): Finder
{
    return (new Finder)->files()
        ->in([catalogRoot('app'), catalogRoot('app-modules'), catalogRoot('resources')])
        ->exclude(['node_modules', 'vendor', 'tests', 'wayfinder', 'actions', 'routes', 'lang'])
        ->name(['*.php', '*.vue', '*.ts']);
}

/** Laravel group-file keys (`auth.failed`) and package keys (`pkg::key`). */
function isNonCatalogKey(string $key): bool
{
    if (str_contains($key, '::')) {
        return true;
    }

    if (preg_match('/^([a-z_]+)\.[a-z_.]+$/', $key, $m)) {
        return is_file(catalogRoot("lang/en/{$m[1]}.php")) || is_file(catalogRoot("lang/de/{$m[1]}.php"));
    }

    return false;
}

/** @return array<string, true> every literal key passed to a translation call */
function usedKeys(): array
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

    foreach (catalogSources() as $file) {
        $regex = str_contains($file->getPathname(), '/resources/js/entrance/') ? $entrance : $pattern;

        if (preg_match_all($regex, $file->getContents(), $m)) {
            foreach ($m[2] as $key) {
                $key = stripcslashes($key);

                if (! isNonCatalogKey($key)) {
                    $keys[$key] = true;
                }
            }
        }
    }

    return $cache = $keys;
}

/**
 * Every string literal in the sources, plus the admin sidebar's module group
 * labels (see header).
 *
 * @return array<string, true>
 */
function sourceLiterals(): array
{
    static $cache = null;

    if ($cache !== null) {
        return $cache;
    }

    $literals = [];
    $sources = [
        ...iterator_to_array(catalogSources(), false),
        ...iterator_to_array((new Finder)->files()->in(catalogRoot('vendor/laravel/framework/src/Illuminate/Foundation/Exceptions/views'))->name('*.php'), false),
    ];

    foreach ($sources as $file) {
        // Rejoin a sentence split across lines: 'a '."\n".'. b' and 'a ' + 'b'.
        $text = preg_replace('/([\'"])\s*[.+]\s*\1/', '', $file->getContents()) ?? '';

        // One line at a time, so a stray apostrophe in a comment or prose
        // cannot pair with a quote further down and invert the file.
        if (preg_match_all('/\'((?:\\\\.|[^\'\\\\\n])*)\'|"((?:\\\\.|[^"\\\\\n])*)"|`((?:\\\\.|[^`\\\\$\n])*)`/', $text, $m)) {
            foreach (array_keys($m[0]) as $i) {
                $literals[stripcslashes($m[1][$i].$m[2][$i].$m[3][$i])] = true;
            }
        }
    }

    // AdminSidebar.vue's humanizeModule(): 'game-ops' -> 'Game Ops'.
    foreach (glob(catalogRoot('app-modules/*'), GLOB_ONLYDIR) ?: [] as $module) {
        $literals[ucwords(str_replace('-', ' ', basename($module)))] = true;
    }

    return $cache = $literals;
}

it('has a german line for every key the source uses', function (): void {
    $known = array_merge(...array_values(catalogs('de')));
    $missing = array_diff_key(usedKeys(), $known);

    expect(array_keys($missing))->toBe([]);
});

it('keeps no german line the source no longer uses', function (): void {
    $used = usedKeys() + sourceLiterals();
    $stale = [];

    foreach (catalogs('de') as $path => $catalog) {
        foreach (array_diff_key($catalog, $used) as $key => $_) {
            $stale[] = "{$path}: {$key}";
        }
    }

    expect($stale)->toBe([]);
});

it('gives one english sentence one german across modules', function (): void {
    $seen = [];
    $conflicts = [];

    foreach (catalogs('de') as $path => $catalog) {
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
| "Secret Key" does not. Add a word here only when a German reader would
| write it the same way; everything else gets translated.
*/
const GERMAN_AS_IS = [
    'admin', 'agenda', 'avatar', 'badge', 'banner', 'brand', 'brands', 'browser', 'bucket', 'check', 'code',
    'community', 'compliance', 'countdown', 'desk', 'details', 'discord', 'e', 'favicon', 'feedback',
    'handle', 'hero', 'host', 'hosts', 'hub', 'id', 'in', 'instagram', 'likes', 'linear', 'link', 'live', 'login',
    'logout', 'markdown', 'mail', 'mm', 'name', 'news', 'newsletter', 'nickname', 'normal', 'offline', 'ok', 'online',
    'optional', 'passkeys', 'payload', 'pdf', 'plus', 'port', 'premium', 'push', 'qr', 'quiz', 'radius', 'region',
    'release', 'route', 'session', 'shop', 'shoutbox', 'sku', 'slug', 'sponsor', 'sponsoring', 'standard', 'start',
    'status', 'steam', 'support', 'symbol', 'system', 'tabletop', 'tag', 'tags', 'team', 'teams', 'teaser', 'text',
    'ticket', 'tickets', 'token', 'track', 'tracks', 'twitch', 'url', 'version', 'viewport', 'wallet', 'website',
    'x', 'y', 'zone',
];

it('never ships an english value as a german line', function (): void {
    $untranslated = [];

    foreach (catalogs('de') as $path => $catalog) {
        foreach ($catalog as $key => $value) {
            if ($value !== $key) {
                continue;
            }

            $prose = preg_replace(['/:[a-z_]+/i', '/\{\d+\}|\[\d+,(?:\d+|\*)\]/'], ' ', $key) ?? '';
            $words = array_filter(preg_split('/[^a-z]+/i', strtolower($prose)) ?: []);

            if (array_diff($words, GERMAN_AS_IS) !== []) {
                $untranslated[] = "{$path}: {$key}";
            }
        }
    }

    expect($untranslated)->toBe([]);
});
