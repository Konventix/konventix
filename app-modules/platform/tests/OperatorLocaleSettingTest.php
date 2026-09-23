<?php

declare(strict_types=1);

use Konventix\Identity\Models\User;
use Konventix\Platform\Models\Operator;
use Konventix\Platform\Models\Permission as PermissionModel;
use Konventix\Platform\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * The operator's default locale (ADR-0068 §4): the bottom of the chain every
 * brand and every person falls back to. Required — an instance that speaks
 * "nothing" has no answer for a guest on a brand without its own choice.
 *
 * @return array{0: Operator, 1: User}
 */
function operatorLocaleAdmin(): array
{
    $operator = Operator::factory()->create();
    $operator->forceFill(['admin_host' => 'admin.operatorlocale.test'])->save();

    $registrar = app(PermissionRegistrar::class);
    $registrar->setPermissionsTeamId($operator->getKey());

    $capabilities = ['platform.access-admin', 'platform.manage-operator'];

    foreach ($capabilities as $name) {
        PermissionModel::query()->firstOrCreate(['name' => $name, 'guard_name' => 'web']);
    }

    $role = Role::query()->create([
        'name' => 'Operator Locale Tester',
        'guard_name' => 'web',
        'brand_id' => $operator->getKey(),
    ]);
    $role->givePermissionTo($capabilities);

    $admin = User::factory()->create();
    $admin->assignRole($role);

    $registrar->setPermissionsTeamId(null);

    return [$operator, $admin];
}

/**
 * @return array<string, mixed>
 */
function operatorLocalePayload(array $overrides = []): array
{
    return array_merge([
        'name' => 'Konventix',
        'legalName' => 'Sund-Xplosion e.V.',
        'countryCode' => 'DE',
        'smallBusinessScheme' => false,
        'signupsOpen' => true,
        'dateFormat' => 'DD.MM.YYYY',
        'timeFormat' => '24h',
        'locale' => 'de',
    ], $overrides);
}

it('shows the operator default locale on the settings page', function (): void {
    [$operator, $admin] = operatorLocaleAdmin();
    $operator->forceFill(['locale' => 'de'])->save();

    $this->actingAs($admin)
        ->get('http://admin.operatorlocale.test/admin/settings/operator')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('operator.locale', 'de'));
});

it('saves the operator default locale', function (): void {
    [$operator, $admin] = operatorLocaleAdmin();

    $this->actingAs($admin)
        ->put('http://admin.operatorlocale.test/admin/settings/operator', operatorLocalePayload())
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    expect($operator->refresh()->locale)->toBe('de');
});

it('requires a default locale the instance ships', function (?string $locale): void {
    [$operator, $admin] = operatorLocaleAdmin();

    $this->actingAs($admin)
        ->put('http://admin.operatorlocale.test/admin/settings/operator', operatorLocalePayload(['locale' => $locale]))
        ->assertSessionHasErrors('locale');

    expect($operator->refresh()->locale)->toBe('en');
})->with([
    'empty' => [''],
    'missing' => [null],
    'not shipped' => ['fr'],
]);
