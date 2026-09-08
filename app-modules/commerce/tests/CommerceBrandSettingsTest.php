<?php

declare(strict_types=1);

use Konventix\Commerce\Models\CommerceBrandSettings;
use Konventix\Commerce\Settlement\RepaymentWindow;
use Konventix\Identity\Models\User;
use Konventix\Platform\Models\Brand;
use Konventix\Platform\Models\Operator;
use Konventix\Platform\Models\Permission as PermissionModel;
use Konventix\Platform\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * The brand's repayment window (ADR-0057 point 4): how many days a payer has
 * to put back money that was reversed before
 * `commerce:cancel-unpaid-reversals` cancels the order. Seven days unless the
 * brand says otherwise — most SEPA returns are a wrong mandate or an empty
 * account, fixed the day the payer is told, and an immediate cancellation
 * would punish a bank error.
 */
/**
 * @return array{0: User, 1: Brand}
 */
function commerceSettingsOperator(array $permissions = ['platform.access-admin', 'commerce.manage-settlement-account']): array
{
    $operator = Operator::factory()->create();
    $operator->forceFill(['admin_host' => 'admin.commerce-settings.test'])->save();

    $registrar = app(PermissionRegistrar::class);
    $registrar->setPermissionsTeamId($operator->getKey());

    foreach ($permissions as $name) {
        PermissionModel::query()->firstOrCreate(['name' => $name, 'guard_name' => 'web']);
    }

    $role = Role::query()->create([
        'name' => 'Operator Admin',
        'guard_name' => 'web',
        'brand_id' => $operator->getKey(),
    ]);
    $role->givePermissionTo($permissions);

    $admin = User::factory()->create();
    $admin->assignRole($role);

    $registrar->setPermissionsTeamId(null);

    $brand = test()->withBrand();

    return [$admin, $brand];
}

it('gives a brand with no settings row the seven-day default', function (): void {
    $brand = $this->withBrand();

    expect(app(RepaymentWindow::class)->daysFor($brand->id))->toBe(RepaymentWindow::DEFAULT_DAYS)
        ->and(RepaymentWindow::DEFAULT_DAYS)->toBe(7);
});

it('lets a brand override the repayment window, and the deadline follows it', function (): void {
    $brand = $this->withBrand();

    CommerceBrandSettings::factory()->create([
        'brand_id' => $brand->id,
        'reversal_repayment_days' => 21,
    ]);

    $window = app(RepaymentWindow::class);

    expect($window->daysFor($brand->id))->toBe(21)
        ->and($window->dueOnFrom(now(), $brand->id)->toDateString())
        ->toBe(now()->startOfDay()->addDays(21)->toDateString());
});

it('reads the setting on the admin host, where no brand is resolved', function (): void {
    [$admin, $brand] = commerceSettingsOperator();

    CommerceBrandSettings::factory()->create(['brand_id' => $brand->id, 'reversal_repayment_days' => 14]);

    $this->actingAs($admin)
        ->get('http://admin.commerce-settings.test/admin/settlement-accounts')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('brands.0.commerceSettings.reversalRepaymentDays', 14));
});

it('saves a new repayment window from the console', function (): void {
    [$admin, $brand] = commerceSettingsOperator();

    $this->actingAs($admin)
        ->put('http://admin.commerce-settings.test/admin/brands/'.$brand->id.'/commerce-settings', [
            'reversalRepaymentDays' => 30,
        ])
        ->assertRedirect();

    expect(app(RepaymentWindow::class)->daysFor($brand->id))->toBe(30)
        ->and(CommerceBrandSettings::withoutBrandScope()->where('brand_id', $brand->id)->count())->toBe(1);
});

it('updates the existing row rather than adding a second one', function (): void {
    [$admin, $brand] = commerceSettingsOperator();

    CommerceBrandSettings::factory()->create(['brand_id' => $brand->id, 'reversal_repayment_days' => 3]);

    $this->actingAs($admin)
        ->put('http://admin.commerce-settings.test/admin/brands/'.$brand->id.'/commerce-settings', [
            'reversalRepaymentDays' => 9,
        ])
        ->assertRedirect();

    expect(CommerceBrandSettings::withoutBrandScope()->where('brand_id', $brand->id)->count())->toBe(1)
        ->and(app(RepaymentWindow::class)->daysFor($brand->id))->toBe(9);
});

it('refuses a window outside one to sixty days', function (): void {
    [$admin, $brand] = commerceSettingsOperator();

    foreach ([0, 61] as $days) {
        $this->actingAs($admin)
            ->put('http://admin.commerce-settings.test/admin/brands/'.$brand->id.'/commerce-settings', [
                'reversalRepaymentDays' => $days,
            ])
            ->assertSessionHasErrors('reversalRepaymentDays');
    }

    expect(app(RepaymentWindow::class)->daysFor($brand->id))->toBe(RepaymentWindow::DEFAULT_DAYS);
});

it('403s an admin without the settlement-account capability', function (): void {
    [$admin, $brand] = commerceSettingsOperator(['platform.access-admin']);

    $this->actingAs($admin)
        ->put('http://admin.commerce-settings.test/admin/brands/'.$brand->id.'/commerce-settings', [
            'reversalRepaymentDays' => 30,
        ])
        ->assertForbidden();
});
