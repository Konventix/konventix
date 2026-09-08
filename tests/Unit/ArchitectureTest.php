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

            // Messaging's declared send API (its SPEC: "The send API modules
            // consume is SendMessage::handle(SendMessageRequest)") — the
            // kernel-tier service every module addresses messages through,
            // plus the template registration surface, plus the topic
            // registration surface (`Topics\Topic`/`TopicRegistry`,
            // `ModuleServiceProvider::registerMessageTopics()`'s own hook —
            // ADR-0048 point 3 is the first module besides Messaging to use
            // it) and the `Channel` enum a topic declaration needs to name
            // its default channels. Models stay forbidden: no module may
            // touch Messaging's log/settings tables.
            if ($importedModule === 'Messaging' && in_array($segments[2] ?? null, ['Actions', 'Data', 'Templates', 'Topics', 'Enums'], true)) {
                continue;
            }

            // Compliance's GDPR and audit contract surfaces: both are
            // published FOR other modules. `Gdpr\GdprHandler` is implemented
            // and registered via registerGdprExporters() (ADR-0003);
            // `Audit\AuditableEvent` is implemented by a module's own event
            // so Compliance's wildcard subscriber records it (Round 6
            // D-BATCH), and `Audit\AuditRecorder` is what a module calls for
            // an action with no event to subscribe to. Compliance's models
            // stay forbidden.
            if ($importedModule === 'Compliance' && in_array($segments[2] ?? null, ['Gdpr', 'Audit', 'Enums'], true)) {
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
|
| These three were `arch()` chains until the branch review, and all three
| evaluated nothing. Modules are path repositories, so `autoload_psr4.php`
| carries `Konventix\Platform\`, `Konventix\Commerce\` and seventeen more
| but no bare `Konventix\`; pest-arch resolves a layer by asking whether the
| *prefix* starts with the expectation, gets an empty directory list, and
| iterates it. `toUseStrictTypes()` then made 0 assertions, and the two
| `->not->` chains had their empty-count failure swallowed by the lazy
| expectation they never verified. The proof was `expect('App')->not->
| toUse('Konventix')` sitting green while `HandleInertiaRequests` imported
| twenty Konventix classes.
|
| So they iterate real files now, through the same `Finder` the two guards
| above use, and the third one says what is actually true rather than what
| read well.
|
*/

/**
 * Every hand-written PHP file the modules and the host ship.
 *
 * Blade templates are excluded: they are markup, and `declare(strict_types=1)`
 * in one would be a syntax error in the middle of a document.
 *
 * @return array<string, string> relative path => contents
 */
function phpSources(string ...$roots): array
{
    $finder = Finder::create()
        ->files()
        ->in(array_map(projectPath(...), $roots))
        ->name('*.php')
        ->notName('*.blade.php');

    $sources = [];

    foreach ($finder as $file) {
        $sources[str_replace(projectPath().'/', '', $file->getRealPath())] = $file->getContents();
    }

    return $sources;
}

it('declares strict types in every module and host file', function (): void {
    $offenders = [];

    foreach (phpSources('app-modules', 'app') as $path => $contents) {
        if (! str_contains($contents, 'declare(strict_types=1);')) {
            $offenders[] = $path;
        }
    }

    expect($offenders)->toBe([])
        // A guard that silently iterates nothing passes forever — which is
        // exactly what the `arch()` chain this replaced did.
        ->and(count(phpSources('app-modules', 'app')))->toBeGreaterThan(900);
});

it('never lets a module depend on the host application layer', function (): void {
    // The direction is one-way by construction (ADR-0003): the host wires
    // modules, modules know nothing about the host. An `App\` import inside
    // `app-modules/` would make a module unusable in any other application,
    // which is the whole point of them being packages.
    $offenders = [];

    foreach (phpSources('app-modules') as $path => $contents) {
        preg_match_all('/^use\s+(App\\\\[^;\s]+)/m', $contents, $uses);

        foreach ($uses[1] as $import) {
            $offenders[] = $path.' → '.$import;
        }
    }

    expect($offenders)->toBe([]);
});

it('keeps a domain module’s models out of the host’s request path', function (): void {
    // The honest form of "models are never touched from the host application
    // layer", which was never true: `HandleInertiaRequests` reads `Brand`,
    // `FooterLink` and `AdminFavorite`, and the install and demo-seed commands
    // create half the schema.
    //
    // What holds is narrower and worth keeping: Platform is the kernel every
    // module is built on and the host may reach its models directly; a
    // *domain* module's models belong to that module, and the host reaches
    // them only from a provisioning command — where the alternative is a
    // module-owned installer the modules do not have.
    $allowed = [
        // A gate closure has to name the type it is handed, and the
        // authenticated user is Identity's model whatever the gate is for.
        'app/Providers/ObservabilityServiceProvider.php → Konventix\Identity\Models\User',
    ];

    $offenders = [];

    foreach (phpSources('app') as $path => $contents) {
        if (str_starts_with($path, 'app/Console/Commands/')) {
            continue;
        }

        preg_match_all('/^use\s+(Konventix\\\\([^\\\\]+)\\\\Models\\\\[^;\s]+)/m', $contents, $uses, PREG_SET_ORDER);

        foreach ($uses as $use) {
            if ($use[2] === 'Platform') {
                continue;
            }

            $entry = $path.' → '.$use[1];

            if (! in_array($entry, $allowed, true)) {
                $offenders[] = $entry;
            }
        }
    }

    expect($offenders)->toBe([]);
});
