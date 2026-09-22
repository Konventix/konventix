<?php

declare(strict_types=1);

use Konventix\Identity\Models\User;
use Konventix\Platform\Models\Brand;
use Konventix\Platform\Models\Operator;
use Konventix\Platform\Models\Permission as PermissionModel;
use Konventix\Platform\Models\Role;
use Konventix\Platform\Theming\ContrastRatio;
use Konventix\Platform\Theming\TokenCatalog;
use Spatie\Permission\PermissionRegistrar;

/**
 * Per-brand dark-mode token overrides (ADR-0008 backlog).
 *
 * `theme_tokens` was one map applied to `:root` regardless of colour scheme,
 * so a brand that set a dark accent got it in light mode too and could say
 * nothing at all about `.dark`. The dark map is a second column rather than a
 * reshaping of the first: every existing brand row keeps meaning exactly what
 * it meant, which for a colour palette is worth more than the tidier
 * `{light, dark}` shape.
 *
 * Helper named apart from ThemeAdminTest's — Pest helpers are process-global
 * across every module's test files (.ai/rules/tests.md).
 *
 * @return array{0: User, 1: Brand}
 */
function operatorWithDarkThemeAdmin(): array
{
    $operator = Operator::factory()->create();
    $operator->forceFill(['admin_host' => 'admin.darktheme.test'])->save();

    $brand = Brand::factory()->withDomain('dark-brand.test')->create();

    $registrar = app(PermissionRegistrar::class);
    $registrar->setPermissionsTeamId($operator->getKey());

    $capabilities = ['platform.access-admin', 'platform.manage-brand-theme'];

    foreach ($capabilities as $name) {
        PermissionModel::query()->firstOrCreate(['name' => $name, 'guard_name' => 'web']);
    }

    $role = Role::query()->create([
        'name' => 'Dark Theme Tester',
        'guard_name' => 'web',
        'brand_id' => $operator->getKey(),
    ]);
    $role->givePermissionTo($capabilities);

    $admin = User::factory()->create();
    $admin->assignRole($role);

    $registrar->setPermissionsTeamId(null);

    return [$admin, $brand];
}

/**
 * The page already ships an `html.dark { }` rule of its own for the initial
 * background, so asserting on `.dark {` across the whole document would match
 * that instead of the brand's. Only the brand block is interesting here.
 */
function brandTokenStyleBlock(string $html): string
{
    $start = strpos($html, '<style id="konventix-brand-tokens">');

    if ($start === false) {
        return '';
    }

    return substr($html, $start, strpos($html, '</style>', $start) - $start);
}

it('saves a dark map alongside the light one and renders both', function (): void {
    [$admin, $brand] = operatorWithDarkThemeAdmin();

    $this->actingAs($admin)
        ->put("http://admin.darktheme.test/admin/brands/{$brand->getKey()}/theme", [
            'tokens' => ['accent' => 'hsl(210 90% 35%)', 'on-accent' => '#ffffff'],
            'darkTokens' => ['accent' => 'hsl(210 90% 70%)', 'on-accent' => '#000000'],
        ])
        ->assertRedirect();

    expect($brand->refresh()->theme_tokens)->toBe(['accent' => 'hsl(210 90% 35%)', 'on-accent' => '#ffffff'])
        ->and($brand->theme_tokens_dark)->toBe(['accent' => 'hsl(210 90% 70%)', 'on-accent' => '#000000']);

    // The light map still lands on `:root`; the dark one lands on `.dark`, so
    // the two cannot fight over the same declaration.
    $block = brandTokenStyleBlock($this->get('http://dark-brand.test/')->assertOk()->getContent());

    expect($block)->toContain(':root {')
        ->and($block)->toContain('.dark {')
        ->and($block)->toContain('--accent: hsl(210 90% 35%);')
        ->and($block)->toContain('--accent: hsl(210 90% 70%);');
});

it('emits the brand block after the stylesheet, so the brand wins the cascade', function (): void {
    // The bug this pins (0.8.1, reported on dev): the block was rendered
    // above @vite. `resources/css/app.css` declares the very same raw tokens
    // on `:root`, at the same specificity, so the stylesheet loaded second and
    // won — a brand could save a palette, see it in the editor's preview, and
    // watch its own site stay default. Nothing in the markup was missing, so
    // asserting that the tokens are *present* never caught it; only their
    // position relative to the stylesheet does.
    [$admin, $brand] = operatorWithDarkThemeAdmin();

    $this->actingAs($admin)
        ->put("http://admin.darktheme.test/admin/brands/{$brand->getKey()}/theme", [
            'tokens' => ['accent' => 'hsl(210 90% 35%)', 'on-accent' => '#ffffff'],
            'darkTokens' => [],
        ])
        ->assertRedirect();

    $html = $this->get('http://dark-brand.test/')->assertOk()->getContent();

    preg_match_all('/<link[^>]+rel="stylesheet"[^>]*>/i', $html, $matches, PREG_OFFSET_CAPTURE);
    $stylesheet = $matches[0] === [] ? false : (int) end($matches[0])[1];
    $block = strpos($html, '<style id="konventix-brand-tokens">');

    expect($stylesheet)->not->toBeFalse('the page has to load the application stylesheet')
        ->and($block)->not->toBeFalse('the brand block has to be rendered')
        ->and($block)->toBeGreaterThan($stylesheet);
});

it('leaves the dark map empty when a brand only themes the light one', function (): void {
    [$admin, $brand] = operatorWithDarkThemeAdmin();

    $this->actingAs($admin)
        ->put("http://admin.darktheme.test/admin/brands/{$brand->getKey()}/theme", [
            'tokens' => ['accent' => 'hsl(210 90% 35%)', 'on-accent' => '#ffffff'],
            'darkTokens' => [],
        ])
        ->assertRedirect();

    expect($brand->refresh()->theme_tokens_dark)->toBe([]);

    // Nothing to say about dark, so nothing is said: the block is absent
    // rather than emitted empty.
    $block = brandTokenStyleBlock($this->get('http://dark-brand.test/')->assertOk()->getContent());

    expect($block)->toContain(':root {')
        ->and($block)->not->toContain('.dark {');
});

/**
 * The contrast guard has to judge each map against its OWN defaults. A dark
 * accent is meant to be light — measured against the light defaults it would
 * look like a failure, and measured the other way a genuine dark failure would
 * pass unnoticed.
 */
it('judges the dark map against the dark defaults, not the light ones', function (): void {
    [$admin, $brand] = operatorWithDarkThemeAdmin();

    $response = $this->actingAs($admin)
        ->from("http://admin.darktheme.test/admin/brands/{$brand->getKey()}/theme")
        ->put("http://admin.darktheme.test/admin/brands/{$brand->getKey()}/theme", [
            'tokens' => [],
            // Near-black text on the dark surface: unreadable in dark mode,
            // and it is only the dark defaults that reveal it.
            'darkTokens' => ['text' => 'hsl(0 0% 6%)'],
        ]);

    $response->assertSessionHasErrors('darkTokens');
    expect(session('errors')->get('darkTokens')[0])->toContain('dark');

    // Refused, so nothing was written at all — the column is still null rather
    // than an empty map.
    expect($brand->refresh()->darkThemeTokens())->toBe([]);
});

it('accepts a dark override that only makes sense in dark mode', function (): void {
    [$admin, $brand] = operatorWithDarkThemeAdmin();

    // Nearly white text — a failure on a light surface, correct on a dark one.
    $this->actingAs($admin)
        ->put("http://admin.darktheme.test/admin/brands/{$brand->getKey()}/theme", [
            'tokens' => [],
            'darkTokens' => ['text' => 'hsl(0 0% 97%)'],
        ])
        ->assertRedirect();

    expect($brand->refresh()->theme_tokens_dark)->toBe(['text' => 'hsl(0 0% 97%)']);
});

it('still rejects unknown token names in the dark map', function (): void {
    [$admin, $brand] = operatorWithDarkThemeAdmin();

    $this->actingAs($admin)
        ->put("http://admin.darktheme.test/admin/brands/{$brand->getKey()}/theme", [
            'tokens' => [],
            'darkTokens' => ['not-a-real-token' => 'hsl(0 0% 0%)'],
        ])
        ->assertSessionHasErrors('darkTokens');
});

it('hands the editor both of the brand maps', function (): void {
    [$admin, $brand] = operatorWithDarkThemeAdmin();
    $brand->update([
        'theme_tokens' => ['accent' => 'hsl(210 90% 35%)'],
        'theme_tokens_dark' => ['accent' => 'hsl(210 90% 70%)'],
    ]);

    $this->actingAs($admin)
        ->get("http://admin.darktheme.test/admin/brands/{$brand->getKey()}/theme")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('tokens.accent', 'hsl(210 90% 35%)')
            ->where('darkTokens.accent', 'hsl(210 90% 70%)'));
});

/**
 * ADR-0008 applies to the platform's own palette, not only to what a brand
 * types. Dark `danger-contrast` on `danger` sat at 3.62:1 until this slice —
 * found by turning the contrast guard on the dark defaults for the first time.
 */
it('ships platform defaults that pass BFSG in both colour schemes', function (): void {
    foreach (['light' => TokenCatalog::lightDefaults(), 'dark' => TokenCatalog::darkDefaults()] as $mode => $defaults) {
        foreach (TokenCatalog::contrastPairs() as $pair) {
            $ratio = ContrastRatio::ratio($defaults[$pair['text']], $defaults[$pair['surface']]);

            expect($ratio)->toBeGreaterThanOrEqual(
                4.5,
                sprintf('%s: %s on %s is %.2f:1', $mode, $pair['text'], $pair['surface'], $ratio),
            );
        }
    }
});
