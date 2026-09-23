<?php

declare(strict_types=1);

use Konventix\Identity\Models\User;
use Konventix\Platform\Models\Brand;
use Konventix\Platform\Models\Operator;
use Konventix\Platform\Models\Permission as PermissionModel;
use Konventix\Platform\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * A brand's own locale (ADR-0068 §4): optional — null means the brand speaks
 * whatever its operator speaks, and the select offers that as "Operator
 * default" so the choice can be handed back.
 */
function brandLocaleAdmin(): User
{
    $operator = Operator::factory()->create();
    $operator->forceFill(['admin_host' => 'admin.brandlocale.test'])->save();

    $registrar = app(PermissionRegistrar::class);
    $registrar->setPermissionsTeamId($operator->getKey());

    $permissions = ['platform.access-admin', 'platform.view-brands', 'platform.manage-brands'];

    foreach ($permissions as $name) {
        PermissionModel::query()->firstOrCreate(['name' => $name, 'guard_name' => 'web']);
    }

    $role = Role::query()->create([
        'name' => 'Brand Locale Editor',
        'guard_name' => 'web',
        'brand_id' => $operator->getKey(),
    ]);
    $role->givePermissionTo($permissions);

    $admin = User::factory()->create();
    $admin->assignRole($role);

    $registrar->setPermissionsTeamId(null);

    return $admin;
}

function brandLocaleFixture(?string $locale = null): Brand
{
    $brand = Brand::factory()->create(['slug' => 'locale-brand', 'locale' => $locale]);
    $brand->domains()->create(['host' => 'locale.brandlocale.test', 'is_primary' => true]);

    return $brand;
}

/**
 * @return array<string, mixed>
 */
function brandLocalePayload(Brand $brand, ?string $locale): array
{
    return [
        'name' => $brand->name,
        'slug' => $brand->slug,
        'currency' => 'EUR',
        'hosts' => ['locale.brandlocale.test'],
        'locale' => $locale,
    ];
}

it('shows the brand locale on the edit page', function (): void {
    $admin = brandLocaleAdmin();
    $brand = brandLocaleFixture('de');

    $this->actingAs($admin)
        ->get("http://admin.brandlocale.test/admin/brands/{$brand->getKey()}/edit")
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('brand.locale', 'de'));
});

it('saves a brand locale', function (): void {
    $admin = brandLocaleAdmin();
    $brand = brandLocaleFixture();

    $this->actingAs($admin)
        ->put("http://admin.brandlocale.test/admin/brands/{$brand->getKey()}", brandLocalePayload($brand, 'de'))
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    expect($brand->refresh()->locale)->toBe('de');
});

it('hands the brand back to the operator default', function (?string $locale): void {
    $admin = brandLocaleAdmin();
    $brand = brandLocaleFixture('de');

    $this->actingAs($admin)
        ->put("http://admin.brandlocale.test/admin/brands/{$brand->getKey()}", brandLocalePayload($brand, $locale))
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    expect($brand->refresh()->locale)->toBeNull();
})->with([
    'empty' => [''],
    'null' => [null],
]);

it('rejects a brand locale the instance does not ship', function (): void {
    $admin = brandLocaleAdmin();
    $brand = brandLocaleFixture('de');

    $this->actingAs($admin)
        ->put("http://admin.brandlocale.test/admin/brands/{$brand->getKey()}", brandLocalePayload($brand, 'fr'))
        ->assertSessionHasErrors('locale');

    expect($brand->refresh()->locale)->toBe('de');
});
