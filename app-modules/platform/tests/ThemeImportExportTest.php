<?php

declare(strict_types=1);

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Konventix\Platform\Actions\ExportBrandTheme;
use Konventix\Platform\Data\BrandThemeExportData;
use Konventix\Platform\Models\Brand;

/**
 * Brand theme export and import (ADR-0008; Task 10 of the 2026-09-21 bugfix
 * round). The export is canonical JSON under a `konventix.brand-theme/v1`
 * marker; the import goes through `BrandThemeExportData`, `BrandThemeData`
 * and `UpdateBrandTheme`, so it is refused exactly where a save would be
 * and the stored theme never changes on a refusal.
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

it('exports the theme as a canonical JSON download', function (): void {
    [, $admin, $brand] = operatorWithThemeAdmin();
    $brand->update(['theme_tokens' => THEME_IO_LIGHT, 'theme_tokens_dark' => []]);

    Carbon::setTestNow('2026-09-21 10:00:00');
    $exportedAt = now()->toIso8601String();

    $response = $this->actingAs($admin)
        ->get("http://admin.theme.test/admin/brands/{$brand->getKey()}/theme/export")
        ->assertOk()
        ->assertHeader('Content-Type', 'application/json')
        ->assertDownload("{$brand->slug}-theme.json");

    $expected = <<<JSON
    {
        "brand": "{$brand->slug}",
        "darkTokens": {},
        "exportedAt": "{$exportedAt}",
        "format": "konventix.brand-theme/v1",
        "tokens": {
            "accent": "hsl(210 90% 45%)",
            "on-accent": "#ffffff"
        }
    }

    JSON;

    expect($response->streamedContent())
        ->toBe($expected)
        ->toBe(app(ExportBrandTheme::class)->handle($brand));
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

    // The export is canonical, so the imported maps arrive key-sorted.
    expect($target->theme_tokens)->toEqualCanonicalizing($source->themeTokens())
        ->and($target->theme_tokens_dark)->toEqualCanonicalizing($source->darkThemeTokens())
        ->and(app(ExportBrandTheme::class)->handle($target))
        ->toBe(str_replace("\"brand\": \"{$source->slug}\"", '"brand": "target-brand"', $export));
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
