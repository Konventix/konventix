<?php

declare(strict_types=1);

use Symfony\Component\Finder\Finder;

/*
|--------------------------------------------------------------------------
| One way to put a timestamp on screen
|--------------------------------------------------------------------------
|
| `resources/js/lib/datetime.ts` reads the viewing person's own zone
| (`users.timezone`, #243) and the operator's date and time format (#242). A
| page that builds its own `Intl.DateTimeFormat` or reaches for
| `toLocaleString()` reads neither, and nothing about the page says so — which
| is how the console's "Placed" column and /my/orders came to print the same
| instant two different ways.
|
| A grep is a blunt rule. A blunt rule is what a sweep needs to stay swept.
|
*/

/**
 * Project root. Unit tests do not boot the framework, so `base_path()` is not
 * available here.
 */
function frontendPath(string $to = ''): string
{
    return rtrim(dirname(__DIR__, 2).'/'.$to, '/');
}

/**
 * Every hand-written Vue and TypeScript source the host and the modules ship.
 *
 * @return array<string, string> relative path => contents
 */
function frontendSources(): array
{
    $finder = (new Finder)
        ->files()
        ->name('*.ts')
        ->name('*.vue')
        ->in([frontendPath('resources/js')])
        ->append(
            (new Finder)
                ->files()
                ->name('*.ts')
                ->name('*.vue')
                ->in(glob(frontendPath('app-modules/*/resources/js'), GLOB_ONLYDIR) ?: []),
        );

    $sources = [];

    foreach ($finder as $file) {
        $sources[str_replace(frontendPath().'/', '', $file->getRealPath())] = $file->getContents();
    }

    return $sources;
}

it('routes every timestamp on screen through the shared helper', function (): void {
    // The helper itself is the one place an `Intl.DateTimeFormat` is built,
    // and its own tests name the API they pin.
    $allowed = [
        'resources/js/lib/datetime.ts',
        'resources/js/lib/datetime.test.ts',
    ];

    $offenders = [];

    foreach (frontendSources() as $path => $contents) {
        if (in_array($path, $allowed, true)) {
            continue;
        }

        if (preg_match('/\bnew Intl\.DateTimeFormat\b|\.toLocale(Date|Time)?String\(/', $contents) === 1) {
            $offenders[] = $path;
        }
    }

    expect($offenders)->toBe([]);
});

/**
 * Every `{{ … }}` and `v-text` in a template, as the expression inside it.
 *
 * @return list<string>
 */
function templateRenderings(string $markup): array
{
    preg_match_all('/\{\{(.+?)\}\}|v-text="([^"]+)"/s', $markup, $matches, PREG_SET_ORDER);

    return array_map(
        static fn (array $match): string => trim($match[2] ?? '' !== '' ? ($match[2] ?? $match[1]) : $match[1]),
        $matches,
    );
}

it('renders no timestamp straight out of a prop', function (): void {
    // Review finding 2: the first rule above only ever saw two call patterns,
    // and roughly fourteen timestamps were being interpolated raw — a
    // `toDateTimeString()` from a controller printed as `2026-09-08 14:05:00`,
    // honouring neither the reader's zone (#243) nor the operator's format
    // (#242). A grep for the *call* could not see them; a grep for the *name*
    // can, because the codebase names its instants and dates consistently.
    //
    // A value that is deliberately raw — an ADR-0027 wall clock, a machine
    // attribute — says so with a `datetime-raw: <expression>` comment in the
    // same file, which is a list the next sweep starts from rather than a
    // grep it has to invent again.
    $dateish = '/\b[a-z]\w*(At|On|Date|Time|Deadline|Since|Until)\b/';

    $offenders = [];

    foreach (frontendSources() as $path => $contents) {
        // `datetime-raw: <expression> — why`, in an HTML comment beside the
        // value. Everything from the em dash on is the reason, for a reader
        // rather than for this test.
        preg_match_all('/datetime-raw:\s*(.+)/', $contents, $markers);
        $allowed = array_map(
            static fn (string $marker): string => trim(explode('—', str_replace('-->', '', $marker))[0]),
            $markers[1],
        );

        // Table columns live in a `columns.ts`, not in a template, and read
        // their value off `row.original`. Same rule, different syntax.
        preg_match_all(
            // Not a *test* on the value — `x !== null ? … : …` asks whether
            // there is one to render, and the rendering itself is matched
            // separately.
            '/(.{0,120})(row\.original\.\w*(?:At|On|Date|Time|Deadline|Since|Until))\b(?!\s*(?:===|!==|==|!=|\?\.|&&|\|\|))/s',
            $contents,
            $cells,
            PREG_SET_ORDER,
        );

        foreach ($cells as $cell) {
            // Wrapped in a `format…()` call, possibly across a line break.
            if (preg_match('/\bformat[A-Za-z]*\s*\(\s*$/', $cell[1]) === 1) {
                continue;
            }

            // The `datetime-raw:` marker lines themselves, and the values
            // they name.
            if (preg_match('/datetime-raw:[^\n]*$/', $cell[1]) === 1
                || in_array($cell[2], $allowed, true)) {
                continue;
            }

            // `<time datetime="…">` and its `h('time', { datetime })` twin:
            // that attribute is machine-readable ISO 8601 by definition and
            // must not be localised — the text inside the element is what a
            // person reads, and that is checked like any other rendering.
            if (preg_match('/datetime["\']?\s*[:=]\s*$/', $cell[1]) === 1) {
                continue;
            }

            $offenders[] = $path.': '.trim(preg_replace('/\s+/', ' ', $cell[0]));
        }

        foreach (templateRenderings($contents) as $expression) {
            if (preg_match($dateish, $expression) !== 1) {
                continue;
            }

            // Wrapped in a `format…()` call, which is the whole point — the
            // shared helpers and the page-local wrappers around them all
            // carry the prefix, which is why it is the naming convention.
            if (preg_match('/\bformat[A-Za-z]*\s*\(/', $expression) === 1) {
                continue;
            }

            if (in_array(preg_replace('/\s+/', ' ', $expression), $allowed, true)) {
                continue;
            }

            $offenders[] = $path.': '.preg_replace('/\s+/', ' ', $expression);
        }
    }

    expect($offenders)->toBe([]);
});

it('is looking at the tree it thinks it is', function (): void {
    // A guard that silently searches nothing passes forever.
    $sources = frontendSources();

    expect(count($sources))->toBeGreaterThan(200)
        ->and($sources)->toHaveKey('resources/js/lib/datetime.ts');
});
