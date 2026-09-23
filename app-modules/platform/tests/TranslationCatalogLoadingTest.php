<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;

/**
 * ADR-0068 §1 and §3: one catalog per module, all of them one map at
 * runtime; §8: a variant overlays its base.
 */
beforeEach(function (): void {
    $this->tmp = storage_path('framework/testing/lang-'.uniqid());
    File::ensureDirectoryExists($this->tmp);
    File::put($this->tmp.'/de.json', json_encode(['Save' => 'Speichern', 'Sign in' => 'Melde dich an']));
    File::put($this->tmp.'/de_formal.json', json_encode(['Sign in' => 'Melden Sie sich an']));
    app('translator')->addJsonPath($this->tmp);
    config()->set('app.locale_variants', ['de_formal' => 'de']);
});

afterEach(function (): void {
    File::deleteDirectory($this->tmp);
});

it('translates from a json path a module registered', function (): void {
    expect(__('Save', [], 'de'))->toBe('Speichern');
});

it('reads a variant over its base', function (): void {
    expect(__('Sign in', [], 'de_formal'))->toBe('Melden Sie sich an')
        ->and(__('Save', [], 'de_formal'))->toBe('Speichern');
});

it('falls back to the english key', function (): void {
    expect(__('Never translated', [], 'de'))->toBe('Never translated');
});

it('registers every module lang directory that exists', function (): void {
    $paths = (fn () => $this->jsonPaths)->call(app('translation.loader')->inner());

    expect($paths)->toContain(base_path('app-modules/commerce/lang'));
})->skip(fn (): bool => ! is_dir(base_path('app-modules/commerce/lang')), 'commerce catalog arrives with Task 6');
