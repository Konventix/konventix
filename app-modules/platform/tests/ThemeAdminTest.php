<?php

declare(strict_types=1);

/**
 * ADR-0008 (BFSG contrast validation, refuse not warn) + ADR-0029 (admin
 * host). `operatorWithThemeAdmin()` lives in tests/Helpers.php since
 * ThemeImportExportTest calls it too (.ai/rules/tests.md).
 */
it('shows the theme editor with the token catalog and the brand current tokens', function (): void {
    [, $admin, $brand] = operatorWithThemeAdmin();
    $brand->update(['theme_tokens' => ['accent' => 'hsl(210 90% 45%)']]);

    $this->actingAs($admin)
        ->get("http://admin.theme.test/admin/brands/{$brand->getKey()}/theme")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('admin/themes/Edit')
            ->where('brand.id', $brand->getKey())
            ->where('tokens.accent', 'hsl(210 90% 45%)')
            ->has('catalog.groups')
            ->has('catalog.contrastPairs'));
});

it('saves valid tokens and they render on the brand host', function (): void {
    [, $admin, $brand] = operatorWithThemeAdmin();

    $this->actingAs($admin)
        ->put("http://admin.theme.test/admin/brands/{$brand->getKey()}/theme", [
            'tokens' => [
                'accent' => 'hsl(210 90% 45%)',
                'on-accent' => '#ffffff',
            ],
        ])
        ->assertRedirect();

    expect($brand->refresh()->theme_tokens)->toBe([
        'accent' => 'hsl(210 90% 45%)',
        'on-accent' => '#ffffff',
    ]);

    $this->get('http://theme-brand.test/')
        ->assertOk()
        ->assertSee('konventix-brand-tokens')
        ->assertSee('--accent: hsl(210 90% 45%);', false)
        ->assertSee('--on-accent: #ffffff;', false);
});

it('rejects unknown token names', function (): void {
    [, $admin, $brand] = operatorWithThemeAdmin();

    $this->actingAs($admin)
        ->put("http://admin.theme.test/admin/brands/{$brand->getKey()}/theme", [
            'tokens' => ['not-a-real-token' => 'hsl(0 0% 0%)'],
        ])
        ->assertSessionHasErrors('tokens');

    expect($brand->refresh()->theme_tokens)->toBe([]);
});

it('rejects values that are not recognized CSS colors', function (): void {
    [, $admin, $brand] = operatorWithThemeAdmin();

    $this->actingAs($admin)
        ->put("http://admin.theme.test/admin/brands/{$brand->getKey()}/theme", [
            'tokens' => ['accent' => 'not-a-color'],
        ])
        ->assertSessionHasErrors('tokens.accent');

    expect($brand->refresh()->theme_tokens)->toBe([]);
});

it('refuses a save whose contrast falls below the BFSG 4.5:1 minimum, naming the pair', function (): void {
    [, $admin, $brand] = operatorWithThemeAdmin();

    $response = $this->actingAs($admin)
        ->from("http://admin.theme.test/admin/brands/{$brand->getKey()}/theme")
        ->put("http://admin.theme.test/admin/brands/{$brand->getKey()}/theme", [
            // White text on white surface: 1:1, nowhere near 4.5:1.
            'tokens' => [
                'text' => '#ffffff',
                'surface' => '#ffffff',
            ],
        ]);

    $response->assertSessionHasErrors('tokens');
    expect(session('errors')->get('tokens')[0])
        ->toContain('text')
        ->toContain('surface')
        ->toContain('1.00:1');

    expect($brand->refresh()->theme_tokens)->toBe([]);
});

it('does not refuse a save that never touches a pair the platform defaults already fail', function (): void {
    // The guard only judges pairs the save actually touches. That began as a
    // workaround — the platform's own light defaults failed three pairs, so
    // checking everything would have refused every save — and slice 15b fixed
    // those defaults in both schemes. It stays as a scoping rule: this Action
    // answers for the combinations an operator chose, and the platform palette
    // answers for itself in BrandDarkThemeTest.
    [, $admin, $brand] = operatorWithThemeAdmin();

    $this->actingAs($admin)
        ->put("http://admin.theme.test/admin/brands/{$brand->getKey()}/theme", [
            'tokens' => ['accent' => 'hsl(210 90% 30%)', 'on-accent' => '#ffffff'],
        ])
        ->assertSessionDoesntHaveErrors('tokens')
        ->assertRedirect();
});

it('403s the editor for an authenticated admin without platform.manage-brand-theme', function (): void {
    [, $admin, $brand] = operatorWithThemeAdmin(['platform.access-admin']);

    $this->actingAs($admin)
        ->get("http://admin.theme.test/admin/brands/{$brand->getKey()}/theme")
        ->assertForbidden();

    $this->actingAs($admin)
        ->put("http://admin.theme.test/admin/brands/{$brand->getKey()}/theme", [
            'tokens' => ['accent' => 'hsl(0 0% 0%)'],
        ])
        ->assertForbidden();
});
