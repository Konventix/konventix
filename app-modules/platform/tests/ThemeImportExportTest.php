<?php

declare(strict_types=1);

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Konventix\Platform\Actions\ExportBrandTheme;
use Konventix\Platform\Data\BrandThemeExportData;
use Konventix\Platform\Models\Brand;
use Konventix\Platform\Theming\TokenCatalog;

/**
 * Brand theme export and import (ADR-0008; Task 10 of the 2026-09-21 bugfix
 * round, widened by the operator ask of 2026-09-22). The export is canonical
 * JSON under a `konventix.brand-theme/v2` marker and carries the whole
 * effective theme plus an authoring guide; the import goes through
 * `ImportBrandThemeData`, `BrandThemeData` and `UpdateBrandTheme`, so it is
 * refused exactly where a save would be and the stored theme never changes on
 * a refusal. A `konventix.brand-theme/v1` file still imports.
 *
 * `operatorWithThemeAdmin()` is in tests/Helpers.php (shared with
 * ThemeAdminTest). The helpers below are this file's own; their names are
 * process-global (.ai/rules/tests.md).
 */
const THEME_IO_LIGHT = ['on-accent' => '#ffffff', 'accent' => 'hsl(210 90% 45%)'];
const THEME_IO_DARK = ['accent' => 'hsl(210 90% 45%)', 'on-accent' => '#ffffff'];

function themeIoUpload(string $contents): UploadedFile
{
    return UploadedFile::fake()->createWithContent('theme.json', $contents);
}

/**
 * A hand-written file: the current envelope, a couple of values and no
 * `overrides` marker — which is the shape an author produces and means
 * "every value here is mine".
 *
 * @param  array<string, mixed>  $overrides
 */
function themeIoDocument(array $overrides = []): string
{
    return (string) json_encode(array_merge([
        'format' => BrandThemeExportData::FORMAT,
        'tokens' => THEME_IO_LIGHT,
        'darkTokens' => THEME_IO_DARK,
    ], $overrides));
}

/**
 * @return array<string, mixed>
 */
function themeIoExportOf(Brand $brand): array
{
    /** @var array<string, mixed> $document */
    $document = json_decode(app(ExportBrandTheme::class)->handle($brand), true, 512, JSON_THROW_ON_ERROR);

    return $document;
}

it('exports the theme as a canonical JSON download', function (): void {
    [, $admin, $brand] = operatorWithThemeAdmin();
    $brand->update(['theme_tokens' => THEME_IO_LIGHT, 'theme_tokens_dark' => []]);

    Carbon::setTestNow('2026-09-21 10:00:00');

    $response = $this->actingAs($admin)
        ->get("http://admin.theme.test/admin/brands/{$brand->getKey()}/theme/export")
        ->assertOk()
        ->assertHeader('Content-Type', 'application/json')
        ->assertDownload("{$brand->slug}-theme.json");

    $json = $response->streamedContent();

    expect($json)->toBe(app(ExportBrandTheme::class)->handle($brand))
        // Canonical: object keys sorted at every level, pretty printed, one
        // trailing newline — so `authoringGuide` heads the file and a reader
        // meets the explanation before the values.
        ->and($json)->toStartWith("{\n    \"authoringGuide\": {\n        \"aboutThisFile\": [\n")
        ->and($json)->toEndWith("}\n")
        ->and(json_decode($json, true))->toMatchArray([
            'brand' => $brand->slug,
            'exportedAt' => '2026-09-21T10:00:00+00:00',
            'format' => 'konventix.brand-theme/v2',
            'overrides' => ['accent', 'on-accent'],
            'darkOverrides' => [],
        ]);
});

it('exports every catalogue token at the platform default for a brand that has themed nothing', function (): void {
    [, , $brand] = operatorWithThemeAdmin();

    $document = themeIoExportOf($brand);

    expect($document['format'])->toBe('konventix.brand-theme/v2')
        ->and($document['tokens'])->toEqualCanonicalizing(TokenCatalog::lightDefaults())
        ->and($document['darkTokens'])->toEqualCanonicalizing(TokenCatalog::darkDefaults())
        ->and(array_keys($document['tokens']))->toEqualCanonicalizing(TokenCatalog::names())
        ->and(array_keys($document['darkTokens']))->toEqualCanonicalizing(TokenCatalog::names())
        // Nothing is the brand's own, which is the distinction the two lists
        // carry: this file is the complete default theme, not a brand that
        // has frozen every default.
        ->and($document['overrides'])->toBe([])
        ->and($document['darkOverrides'])->toBe([]);
});

it('exports a brand\'s two overrides plus the defaults for the rest, and marks which were its own', function (): void {
    [, , $brand] = operatorWithThemeAdmin();
    $brand->update(['theme_tokens' => THEME_IO_LIGHT, 'theme_tokens_dark' => []]);

    $document = themeIoExportOf($brand);

    expect($document['overrides'])->toBe(['accent', 'on-accent'])
        ->and($document['darkOverrides'])->toBe([])
        ->and($document['tokens']['accent'])->toBe('hsl(210 90% 45%)')
        ->and($document['tokens']['on-accent'])->toBe('#ffffff')
        ->and($document['tokens']['surface'])->toBe(TokenCatalog::lightDefaults()['surface'])
        ->and(array_keys($document['tokens']))->toEqualCanonicalizing(TokenCatalog::names())
        // Dark was never touched, so every dark value is still the default.
        ->and($document['darkTokens'])->toEqualCanonicalizing(TokenCatalog::darkDefaults());
});

it('writes an authoring guide naming every token and the contrast rule', function (): void {
    [, , $brand] = operatorWithThemeAdmin();

    $guide = themeIoExportOf($brand)['authoringGuide'];

    expect(array_keys($guide['tokens']))->toEqualCanonicalizing(TokenCatalog::names())
        ->and($guide['tokens']['on-accent'])->toBe([
            'defaultDark' => 'hsl(0 0% 9%)',
            'defaultLight' => 'hsl(0 0% 98%)',
            'group' => 'brand',
            'groupLabel' => 'Brand',
            'label' => 'On accent',
        ])
        ->and($guide['contrastRule']['minimumRatio'])->toBe(TokenCatalog::MIN_CONTRAST_RATIO)
        ->and($guide['contrastRule']['pairs'])->toEqualCanonicalizing(
            array_map(
                static fn (array $pair): array => ['surface' => $pair['surface'], 'text' => $pair['text']],
                TokenCatalog::contrastPairs(),
            ),
        )
        ->and($guide['aboutThisFile'])->not->toBeEmpty();
});

it('writes two exports byte-identical apart from exportedAt', function (): void {
    [, , $brand] = operatorWithThemeAdmin();
    $brand->update(['theme_tokens' => THEME_IO_LIGHT, 'theme_tokens_dark' => THEME_IO_DARK]);

    Carbon::setTestNow('2026-09-21 10:00:00');
    $first = app(ExportBrandTheme::class)->handle($brand);

    Carbon::setTestNow('2026-09-22 11:30:00');
    $second = app(ExportBrandTheme::class)->handle($brand);

    $withoutStamp = fn (string $json): string => (string) preg_replace('/^\s*"exportedAt": .*\n/m', '', $json);

    expect($first)->not->toBe($second)
        ->and($withoutStamp($first))->toBe($withoutStamp($second))
        ->and($first)->toContain('"exportedAt": "2026-09-21T10:00:00+00:00"')
        ->and($second)->toContain('"exportedAt": "2026-09-22T11:30:00+00:00"');
});

it('imports the export of one brand into another', function (): void {
    [, $admin, $source] = operatorWithThemeAdmin();
    $source->update(['theme_tokens' => THEME_IO_LIGHT, 'theme_tokens_dark' => THEME_IO_DARK]);
    $target = Brand::factory()->create(['name' => 'Target Brand', 'slug' => 'target-brand']);

    $export = app(ExportBrandTheme::class)->handle($source);

    $this->actingAs($admin)
        ->post("http://admin.theme.test/admin/brands/{$target->getKey()}/theme/import", [
            'file' => themeIoUpload($export),
        ])
        ->assertSessionHasNoErrors()
        ->assertRedirect("http://admin.theme.test/admin/brands/{$target->getKey()}/theme");

    $target->refresh();

    // The export carries every catalogue token, but only the two the source
    // marks as its own are stored — the rest keep following the platform
    // default. The export is canonical, so they arrive key-sorted.
    expect($target->theme_tokens)->toEqualCanonicalizing($source->themeTokens())
        ->and($target->theme_tokens_dark)->toEqualCanonicalizing($source->darkThemeTokens())
        ->and(app(ExportBrandTheme::class)->handle($target))
        ->toBe(str_replace("\"brand\": \"{$source->slug}\"", '"brand": "target-brand"', $export));
});

it('round-trips a brand that follows the defaults without freezing them', function (): void {
    [, $admin, $source] = operatorWithThemeAdmin();
    $target = Brand::factory()->create(['name' => 'Target Brand', 'slug' => 'target-brand']);

    $this->actingAs($admin)
        ->post("http://admin.theme.test/admin/brands/{$target->getKey()}/theme/import", [
            'file' => themeIoUpload(app(ExportBrandTheme::class)->handle($source)),
        ])
        ->assertSessionHasNoErrors();

    // The file held all 19 values in both schemes and claimed none of them.
    // Storing them would silently turn a brand that follows the platform
    // palette into one that has frozen today's copy of it.
    expect($target->refresh()->theme_tokens)->toBe([])
        ->and($target->theme_tokens_dark)->toBe([]);
});

it('imports a konventix.brand-theme/v1 file, whose maps are overrides and nothing else', function (): void {
    [, $admin, $brand] = operatorWithThemeAdmin();

    $v1 = (string) json_encode([
        'format' => 'konventix.brand-theme/v1',
        'tokens' => THEME_IO_LIGHT,
        'darkTokens' => THEME_IO_DARK,
        'exportedAt' => '2026-09-21T10:00:00+00:00',
        'brand' => 'some-other-brand',
    ]);

    $this->actingAs($admin)
        ->post("http://admin.theme.test/admin/brands/{$brand->getKey()}/theme/import", [
            'file' => themeIoUpload($v1),
        ])
        ->assertSessionHasNoErrors();

    expect($brand->refresh()->theme_tokens)->toEqualCanonicalizing(THEME_IO_LIGHT)
        ->and($brand->theme_tokens_dark)->toEqualCanonicalizing(THEME_IO_DARK);
});

it('takes a hand-written file with no markers as the brand\'s own overrides', function (): void {
    [, $admin, $brand] = operatorWithThemeAdmin();

    $this->actingAs($admin)
        ->post("http://admin.theme.test/admin/brands/{$brand->getKey()}/theme/import", [
            'file' => themeIoUpload(themeIoDocument()),
        ])
        ->assertSessionHasNoErrors();

    expect($brand->refresh()->theme_tokens)->toEqualCanonicalizing(THEME_IO_LIGHT)
        ->and($brand->theme_tokens_dark)->toEqualCanonicalizing(THEME_IO_DARK)
        // And the export of that brand now marks exactly those two.
        ->and(themeIoExportOf($brand)['overrides'])->toBe(['accent', 'on-accent']);
});

it('ignores the authoring guide, the brand and the timestamp a file carries', function (): void {
    [, $admin, $brand] = operatorWithThemeAdmin();

    $document = (string) json_encode([
        'format' => BrandThemeExportData::FORMAT,
        'tokens' => THEME_IO_LIGHT,
        'darkTokens' => THEME_IO_DARK,
        'brand' => 'somebody-else',
        'exportedAt' => 'whenever',
        // Nonsense where a guide belongs: it is reference, never read back,
        // so it must not be able to refuse a file whose values are fine.
        'authoringGuide' => 'I edited this by hand',
    ]);

    $this->actingAs($admin)
        ->post("http://admin.theme.test/admin/brands/{$brand->getKey()}/theme/import", [
            'file' => themeIoUpload($document),
        ])
        ->assertSessionHasNoErrors();

    expect($brand->refresh()->theme_tokens)->toEqualCanonicalizing(THEME_IO_LIGHT);
});

it('refuses an import the theme editor would refuse, leaving the theme unchanged', function (string $document, string $errorKey): void {
    [, $admin, $brand] = operatorWithThemeAdmin();
    $brand->update(['theme_tokens' => ['accent' => 'hsl(0 0% 20%)'], 'theme_tokens_dark' => []]);

    $this->actingAs($admin)
        ->post("http://admin.theme.test/admin/brands/{$brand->getKey()}/theme/import", [
            'file' => themeIoUpload($document),
        ])
        ->assertSessionHasErrors($errorKey);

    expect($brand->refresh()->theme_tokens)->toBe(['accent' => 'hsl(0 0% 20%)'])
        ->and($brand->theme_tokens_dark)->toBe([]);
})->with([
    'unknown token name' => [themeIoDocument(['tokens' => ['not-a-real-token' => '#000000']]), 'tokens'],
    'bad colour' => [themeIoDocument(['tokens' => ['accent' => 'not-a-color']]), 'tokens.accent'],
    'low contrast' => [themeIoDocument(['tokens' => ['text' => '#ffffff', 'surface' => '#ffffff']]), 'tokens'],
    'low contrast in dark mode' => [themeIoDocument(['darkTokens' => ['text' => '#000000', 'surface' => '#000000']]), 'darkTokens'],
    'wrong format' => [themeIoDocument(['format' => 'konventix.plan/v1']), 'format'],
    'no format' => [(string) json_encode(['tokens' => THEME_IO_LIGHT]), 'format'],
]);

it('names the contrast pair when refusing a low-contrast import', function (): void {
    [, $admin, $brand] = operatorWithThemeAdmin();

    $this->actingAs($admin)
        ->post("http://admin.theme.test/admin/brands/{$brand->getKey()}/theme/import", [
            'file' => themeIoUpload(themeIoDocument(['tokens' => ['text' => '#ffffff', 'surface' => '#ffffff']])),
        ])
        ->assertSessionHasErrors('tokens');

    expect(session('errors')->get('tokens')[0])
        ->toContain('text')
        ->toContain('surface')
        ->toContain('1.00:1');
});

it('refuses a file that is not a JSON object with a file error', function (string $contents): void {
    [, $admin, $brand] = operatorWithThemeAdmin();
    $brand->update(['theme_tokens' => ['accent' => 'hsl(0 0% 20%)']]);

    $this->actingAs($admin)
        ->post("http://admin.theme.test/admin/brands/{$brand->getKey()}/theme/import", [
            'file' => themeIoUpload($contents),
        ])
        ->assertSessionHasErrors('file');

    expect($brand->refresh()->theme_tokens)->toBe(['accent' => 'hsl(0 0% 20%)']);
})->with([
    'not json' => '{not json',
    'a list' => '[1, 2]',
    'a string' => '"konventix.brand-theme/v1"',
]);

it('requires a file', function (): void {
    [, $admin, $brand] = operatorWithThemeAdmin();

    $this->actingAs($admin)
        ->post("http://admin.theme.test/admin/brands/{$brand->getKey()}/theme/import", [])
        ->assertSessionHasErrors('file');
});

it('403s export and import for an admin without platform.manage-brand-theme', function (): void {
    [, $admin, $brand] = operatorWithThemeAdmin(['platform.access-admin']);

    $this->actingAs($admin)
        ->get("http://admin.theme.test/admin/brands/{$brand->getKey()}/theme/export")
        ->assertForbidden();

    $this->actingAs($admin)
        ->post("http://admin.theme.test/admin/brands/{$brand->getKey()}/theme/import", [
            'file' => themeIoUpload(themeIoDocument()),
        ])
        ->assertForbidden();

    expect($brand->refresh()->theme_tokens)->toBe([]);
});
