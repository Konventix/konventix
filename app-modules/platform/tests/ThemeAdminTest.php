<?php

declare(strict_types=1);

use Konventix\Identity\Models\User;
use Konventix\Platform\Models\Brand;
use Konventix\Platform\Models\Operator;
use Konventix\Platform\Models\Permission as PermissionModel;
use Konventix\Platform\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * ADR-0008 (BFSG contrast validation, refuse not warn) + ADR-0029 (admin
 * host). Mirrors OperatorHostTest's operatorWithAdmin() helper, under a
 * different name to avoid redeclaring it, and grants exactly the
 * capabilities each test needs so the 403 test proves platform.access-admin
 * alone is not enough.
 *
 * @param  array<int, string>  $capabilities
 * @return array{0: Operator, 1: User, 2: Brand}
 */
function operatorWithThemeAdmin(array $capabilities = ['platform.access-admin', 'platform.manage-brand-theme']): array
{
    $operator = Operator::factory()->create();
    $operator->forceFill(['admin_host' => 'admin.theme.test'])->save();

    $brand = Brand::factory()->withDomain('theme-brand.test')->create();

    $registrar = app(PermissionRegistrar::class);
    $registrar->setPermissionsTeamId($operator->getKey());

    foreach ($capabilities as $name) {
        PermissionModel::query()->firstOrCreate([
            'name' => $name,
            'guard_name' => 'web',
        ]);
    }

    $role = Role::query()->create([
        'name' => 'Theme Tester',
        'guard_name' => 'web',
        'brand_id' => $operator->getKey(),
    ]);
    $role->givePermissionTo($capabilities);

    $admin = User::factory()->create();
    $admin->assignRole($role);

    $registrar->setPermissionsTeamId(null);

    return [$operator, $admin, $brand];
}

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
    // TokenCatalog's light defaults for danger/success/warning contrast fall
    // short of 4.5:1 on their own (found while building this feature — the
    // starter-kit palette was never accessibility-audited). A save that only
    // touches the brand accent must not be blocked by that unrelated,
    // pre-existing gap.
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
