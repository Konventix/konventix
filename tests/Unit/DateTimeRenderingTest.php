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

        if (preg_match('/\bnew Intl\.DateTimeFormat\b|\.toLocale(String|DateString|TimeString)\(/', $contents) === 1) {
            $offenders[] = $path;
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
