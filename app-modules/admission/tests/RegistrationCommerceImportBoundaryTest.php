<?php

declare(strict_types=1);

use Symfony\Component\Finder\Finder;

/**
 * TODO.md: "Write the arch/feature test that proves Admission imports no
 * Registration or Commerce model, and that issuing depends only on the event
 * payload."
 *
 * The project-wide `tests/Unit/ArchitectureTest.php` already forbids
 * cross-module imports outside a foreign module's `Events\` namespace, and
 * Admission is only ever allowed to reach into
 * `Konventix\Registration\Events\RegistrationConfirmed` (ADR-0004) — see
 * `IssueAdmissionsOnRegistrationConfirmed`. This test is Admission's own,
 * stricter check: it forbids `Konventix\Commerce` entirely (this module has
 * no reason to import anything from Commerce, not even an event, in Phase 1)
 * and forbids every `Konventix\Registration` import except the one `Events\`
 * class this module is built around.
 */
function admissionSourceFiles(): array
{
    $finder = Finder::create()
        ->files()
        ->in(dirname(__DIR__).'/src')
        ->name('*.php');

    $files = [];

    foreach ($finder as $file) {
        $files[] = [
            'path' => 'app-modules/admission/src/'.$file->getRelativePathname(),
            'contents' => $file->getContents(),
        ];
    }

    return $files;
}

it('never imports Konventix\Commerce anywhere in src/', function (): void {
    $violations = [];

    foreach (admissionSourceFiles() as $file) {
        if (preg_match('/^use\s+Konventix\\\\Commerce\\\\/m', $file['contents']) === 1) {
            $violations[] = $file['path'];
        }
    }

    expect($violations)->toBe([], implode("\n", [
        'Admission must never import a Commerce class (SPEC.md "Does not own").',
        '',
        ...$violations,
    ]));
});

it('never imports Konventix\Registration outside its Events\ namespace', function (): void {
    $violations = [];

    foreach (admissionSourceFiles() as $file) {
        if (preg_match_all('/^use\s+(Konventix\\\\Registration\\\\[^;\s]+)/m', $file['contents'], $matches) === 0) {
            continue;
        }

        foreach ($matches[1] as $import) {
            if (! str_starts_with($import, 'Konventix\\Registration\\Events\\')) {
                $violations[] = "{$file['path']} imports {$import}";
            }
        }
    }

    expect($violations)->toBe([], implode("\n", [
        'Admission may only reach into Registration\'s Events\ namespace',
        '(the public contract, ADR-0004) — never a Model, Action, or Data class.',
        '',
        ...$violations,
    ]));
});

it('never imports Konventix\Staffing outside its Events\ namespace', function (): void {
    // D-STF-01 gives Admission a second sanctioned foreign import: Staffing's
    // AssignmentAccepted, which produces a crew badge. The same rule applies
    // as for Registration — the Events\ namespace is the contract, and a
    // Model or Action never is (ADR-0004). Without this case the boundary
    // would hold only by the accident of Staffing having been empty.
    $violations = [];

    foreach (admissionSourceFiles() as $file) {
        if (preg_match_all('/^use\s+(Konventix\\\\Staffing\\\\[^;\s]+)/m', $file['contents'], $matches) === 0) {
            continue;
        }

        foreach ($matches[1] as $import) {
            if (! str_starts_with($import, 'Konventix\\Staffing\\Events\\')) {
                $violations[] = "{$file['path']} imports {$import}";
            }
        }
    }

    expect($violations)->toBe([], implode("\n", [
        'Admission may only reach into Staffing\'s Events\ namespace',
        '(the public contract, ADR-0004) — never a Model, Action, or Data class.',
        '',
        ...$violations,
    ]));
});
