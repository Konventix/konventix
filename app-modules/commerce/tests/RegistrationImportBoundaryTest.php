<?php

declare(strict_types=1);

use Symfony\Component\Finder\Finder;

/**
 * Commerce consumes `RegistrationSettlementRequested` (SPEC.md "Consumes"),
 * so — like Admission — it may import Registration's `Events\` namespace
 * and nothing else: events are the public contract between modules
 * (ADR-0004), models never cross. The Phase 1 version of this test forbade
 * any Registration import at all, which was correct while Commerce was
 * dormant by construction (ADR-0026); the exemption below is the intended
 * evolution, not a loosening.
 */
it('imports Konventix\Registration only from its Events namespace in src/', function (): void {
    $finder = Finder::create()
        ->files()
        ->in(dirname(__DIR__).'/src')
        ->name('*.php');

    $violations = [];

    foreach ($finder as $file) {
        preg_match_all('/^use\s+(Konventix\\\\Registration\\\\[^;]+);/m', $file->getContents(), $matches);

        foreach ($matches[1] as $import) {
            if (! str_starts_with($import, 'Konventix\\Registration\\Events\\')) {
                $violations[] = 'app-modules/commerce/src/'.$file->getRelativePathname().' imports '.$import;
            }
        }
    }

    expect($violations)->toBe([], implode("\n", [
        'Commerce may consume Registration events but never its models or',
        'other internals (ADR-0004, ADR-0006).',
        '',
        ...$violations,
    ]));
});
