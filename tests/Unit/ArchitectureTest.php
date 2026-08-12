<?php

declare(strict_types=1);

use Symfony\Component\Finder\Finder;

/*
|--------------------------------------------------------------------------
| Module boundary enforcement
|--------------------------------------------------------------------------
|
| ADR-0004 requires the build to fail on cross-module model imports. LanCore's
| DDD boundaries existed on paper only — Competition imported 25 foreign models,
| Shop 24, Event 21 — because nothing checked. This is the check.
|
| The rule: a module may reach into another module's `Events\` namespace (events
| are the public contract) and into `Konventix\Platform\` (the kernel every
| module is built on). Everything else across a module boundary is a violation.
|
*/

/**
 * Project root. Unit tests do not boot the framework, so `base_path()` is not
 * available here — and an architecture test should not need a booted app.
 */
function projectPath(string $to = ''): string
{
    return rtrim(dirname(__DIR__, 2).'/'.$to, '/');
}

/**
 * Every PHP file under app-modules/, with its declared namespace and imports.
 *
 * @return array<int, array{path: string, module: string, imports: array<int, string>}>
 */
function moduleSourceFiles(): array
{
    static $files = null;

    if ($files !== null) {
        return $files;
    }

    $finder = Finder::create()
        ->files()
        ->in(projectPath('app-modules'))
        ->name('*.php');

    $files = [];

    foreach ($finder as $file) {
        $contents = $file->getContents();

        if (preg_match('/^namespace\s+(Konventix\\\\[^;]+);/m', $contents, $namespace) !== 1) {
            continue;
        }

        preg_match_all('/^use\s+(Konventix\\\\[^;\s]+)/m', $contents, $uses);

        $files[] = [
            'path' => str_replace(projectPath().'/', '', $file->getRealPath()),
            'module' => explode('\\', $namespace[1])[1],
            'imports' => $uses[1],
        ];
    }

    return $files;
}

it('never imports another module internals across a boundary', function (): void {
    $violations = [];

    foreach (moduleSourceFiles() as $file) {
        foreach ($file['imports'] as $import) {
            $segments = explode('\\', $import);
            $importedModule = $segments[1] ?? null;

            // Same module, or the Platform kernel every module builds on.
            if ($importedModule === $file['module'] || $importedModule === 'Platform') {
                continue;
            }

            // Events are the public contract between modules (ADR-0004).
            if (($segments[2] ?? null) === 'Events') {
                continue;
            }

            $violations[] = "{$file['path']} imports {$import}";
        }
    }

    expect($violations)->toBe([], implode("\n", [
        'Cross-module import detected. Modules talk through events only (ADR-0004).',
        'Reference foreign entities by ULID; if you need foreign display data,',
        'maintain a read model in your own module fed by the owner\'s events.',
        '',
        ...$violations,
    ]));
});

it('never imports another module Eloquent model, not even from Platform kernel code', function (): void {
    $violations = [];

    foreach (moduleSourceFiles() as $file) {
        foreach ($file['imports'] as $import) {
            $segments = explode('\\', $import);

            if (($segments[1] ?? null) === $file['module']) {
                continue;
            }

            // Platform's Brand is the scoping primitive and Role/Permission are
            // the authorization primitives; those are infrastructure, not domain
            // data. Models\Concerns\* holds the traits (BelongsToBrand) every
            // brand-owned model MUST use (ADR-0010) — mechanism, not data.
            // Any *other* foreign model is a boundary violation.
            $isPlatformPrimitive = ($segments[1] ?? null) === 'Platform'
                && (
                    in_array($import, [
                        'Konventix\Platform\Models\Brand',
                        'Konventix\Platform\Models\Role',
                        'Konventix\Platform\Models\Permission',
                    ], true)
                    || str_starts_with($import, 'Konventix\Platform\Models\Concerns\\')
                    || str_starts_with($import, 'Konventix\Platform\Models\Scopes\\')
                );

            if (($segments[2] ?? null) === 'Models' && ! $isPlatformPrimitive) {
                $violations[] = "{$file['path']} imports the foreign model {$import}";
            }
        }
    }

    expect($violations)->toBe([], implode("\n", [
        'A module imported another module\'s Eloquent model (ADR-0004).',
        '',
        ...$violations,
    ]));
});

/*
|--------------------------------------------------------------------------
| Module registration conventions
|--------------------------------------------------------------------------
*/

it('registers every module through a provider extending ModuleServiceProvider', function (): void {
    $providers = Finder::create()
        ->files()
        ->in(projectPath('app-modules'))
        ->path('src/Providers')
        ->name('*ServiceProvider.php');

    expect(iterator_count($providers))->toBe(19);

    $offenders = [];

    foreach ($providers as $provider) {
        if (! str_contains($provider->getContents(), 'extends ModuleServiceProvider')) {
            $offenders[] = $provider->getFilename();
        }
    }

    expect($offenders)->toBe(
        [],
        'Every module provider must extend ModuleServiceProvider so routes, policies, '
        .'listeners, navigation, features and GDPR exporters register the same way (ADR-0003).'
    );
});

it('keeps module route files out of the directory modular auto-requires', function (): void {
    // InterNACHI/modular requires every `routes/*.php` at depth 0 with NO
    // middleware — a route there would escape both `web` and the activation
    // gate. Module routes belong in `routes/web/`, loaded by the provider.
    $stray = glob(projectPath('app-modules/*/routes/*.php')) ?: [];

    expect(array_map(fn (string $p): string => str_replace(projectPath().'/', '', $p), $stray))
        ->toBe([], 'Move these into routes/web/ so ModuleServiceProvider applies the module gate.');
});

it('never lists a module provider in the central bootstrap file', function (): void {
    // ADR-0003: modules self-register. LanCore's 408-line central provider
    // wiring every domain is the anti-example.
    expect(file_get_contents(projectPath('bootstrap/providers.php')))
        ->not->toContain('Konventix\\');
});

/*
|--------------------------------------------------------------------------
| Language-level conventions
|--------------------------------------------------------------------------
*/

arch('every module file declares strict types')
    ->expect('Konventix')
    ->toUseStrictTypes();

arch('the domain never depends on the host application layer')
    ->expect('App')
    ->not->toBeUsedIn('Konventix');

arch('models are never touched from the host application layer')
    ->expect('App')
    ->not->toUse('Konventix');
