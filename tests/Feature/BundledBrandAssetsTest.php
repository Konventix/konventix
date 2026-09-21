<?php

declare(strict_types=1);

/*
 * The bundled icon set is what every brand wears until an operator uploads a
 * favicon of its own. It is the Konventix mark from the brand repository
 * (konventix-brand, assets/export/web), not the Laravel starter-kit artwork
 * the project was scaffolded with. Which links the document head carries is
 * covered by app-modules/platform/tests/BrandIdentityTest.php; this file
 * pins the files those links point at.
 */

/** The K stem of the canonical monogram, assets/logo/mark/konventix-mark-ink.svg. */
const KONVENTIX_MARK_STEM = 'M0 0H182V254L298 364 182 474V730H0Z';

it('ships a web manifest whose icons exist', function (): void {
    $manifest = json_decode((string) file_get_contents(public_path('manifest.json')), true, flags: JSON_THROW_ON_ERROR);

    expect($manifest['name'])->toBe('Konventix')
        ->and($manifest['icons'])->not->toBeEmpty();

    foreach ($manifest['icons'] as $icon) {
        expect(public_path($icon['src']))->toBeFile();
    }
});

it('bundles the Konventix mark as the SVG favicon', function (): void {
    expect((string) file_get_contents(public_path('favicon.svg')))->toContain(KONVENTIX_MARK_STEM);
});

it('renders the Konventix mark as the fallback logo', function (): void {
    $component = (string) file_get_contents(resource_path('js/components/AppLogoIcon.vue'));

    expect($component)->toContain(KONVENTIX_MARK_STEM)
        ->and($component)->toContain('viewBox="0 0 701 732"')
        ->and($component)->not->toContain('TODO(brand)');
});
