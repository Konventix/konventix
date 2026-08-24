<?php

declare(strict_types=1);

use Inertia\Testing\AssertableInertia;
use Konventix\Identity\Models\User;
use Konventix\Platform\Models\Operator;
use Konventix\Platform\Models\Permission as PermissionModel;
use Konventix\Platform\Models\Role;
use Konventix\Platform\Modules\ModuleGate;
use Konventix\Platform\Navigation\NavigationItem;
use Konventix\Platform\Navigation\NavigationRegistry;
use Laravel\Pennant\Feature;
use Spatie\Permission\PermissionRegistrar;

/**
 * The brand shell's navigation comes from the registry, not from a hardcoded
 * list in the topbar — so it is subject to the same per-brand module
 * activation gate as every other enforcement point (ADR-0003, point 2).
 */
it('shares the brand area\'s navigation with brand pages', function (): void {
    $this->withBrand();

    $this->get('/')
        ->assertOk()
        ->assertInertia(function (AssertableInertia $page): void {
            $labels = collect($page->toArray()['props']['nav'])->pluck('label');

            expect($labels)->toContain('Home');
        });
});

it('hides a deactivated module\'s brand navigation', function (): void {
    $brand = $this->withBrand();

    app(NavigationRegistry::class)->add(new NavigationItem(
        module: 'staffing',
        label: 'Shifts',
        url: '/shifts',
        area: NavigationItem::AREA_BRAND,
    ));

    $labelsFor = fn (AssertableInertia $page): array => collect(
        $page->toArray()['props']['nav'],
    )->pluck('label')->all();

    $this->get('/')
        ->assertInertia(fn (AssertableInertia $page) => expect($labelsFor($page))->not->toContain('Shifts'));

    Feature::for($brand)->activate(app(ModuleGate::class)->featureName('staffing'));

    $this->get('/')
        ->assertInertia(fn (AssertableInertia $page) => expect($labelsFor($page))->toContain('Shifts'));
});

it('shares no brand navigation on the operator admin host', function (): void {
    $operator = Operator::factory()->create();
    $operator->forceFill(['admin_host' => 'admin.shell.test'])->save();

    $registrar = app(PermissionRegistrar::class);
    $registrar->setPermissionsTeamId($operator->getKey());
    PermissionModel::query()->firstOrCreate(['name' => 'platform.access-admin', 'guard_name' => 'web']);

    $role = Role::query()->create([
        'name' => 'Console',
        'guard_name' => 'web',
        'brand_id' => $operator->getKey(),
    ]);
    $role->givePermissionTo('platform.access-admin');

    $admin = User::factory()->create();
    $admin->assignRole($role);
    $registrar->setPermissionsTeamId(null);

    // The console has its own sidebar navigation (`admin.navigation`); the
    // brand topbar's list would be meaningless there, where no brand is
    // resolved at all.
    $this->actingAs($admin)
        ->get('http://admin.shell.test/admin')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->where('nav', []));
});
