<?php

declare(strict_types=1);

use Inertia\Testing\AssertableInertia;
use Konventix\Identity\Models\User;
use Konventix\Platform\Models\Operator;
use Konventix\Platform\Models\Permission as PermissionModel;
use Konventix\Platform\Models\Role;
use Konventix\Platform\Navigation\NavigationItem;
use Spatie\Permission\PermissionRegistrar;

/**
 * The console sidebar renders what `admin.navigation` says, and one thing it
 * has to say per entry is whether the URL is an Inertia page at all (GitLab
 * #61): Horizon, Pulse and the health page are Blade. Clicked through an
 * Inertia `Link` their HTML comes back over XHR and lands in Inertia's error
 * modal — an empty page with the Horizon background — so the shell needs the
 * `external` flag to render a plain anchor instead.
 */
function adminNavigationOperator(): User
{
    $operator = Operator::factory()->create();
    $operator->forceFill(['admin_host' => 'admin.navigation.test'])->save();

    $registrar = app(PermissionRegistrar::class);
    $registrar->setPermissionsTeamId($operator->getKey());

    foreach (['platform.access-admin', 'platform.view-diagnostics'] as $name) {
        PermissionModel::query()->firstOrCreate(['name' => $name, 'guard_name' => 'web']);
    }

    $role = Role::query()->create([
        'name' => 'Navigation Tester',
        'guard_name' => 'web',
        'brand_id' => $operator->getKey(),
    ]);
    $role->givePermissionTo(['platform.access-admin', 'platform.view-diagnostics']);

    $admin = User::factory()->create();
    $admin->assignRole($role);

    $registrar->setPermissionsTeamId(null);

    return $admin;
}

it('is an Inertia page unless the entry says otherwise', function (): void {
    $page = new NavigationItem(module: 'platform', label: 'Brands', url: '/admin/brands');
    $blade = new NavigationItem(module: 'platform', label: 'Queues', url: '/admin/horizon', external: true);

    expect($page->toArray())->toMatchArray(['external' => false])
        ->and($blade->toArray())->toMatchArray(['external' => true]);
});

it('marks the package dashboards and the health page as full page loads', function (): void {
    $admin = adminNavigationOperator();

    $this->actingAs($admin)
        ->get('http://admin.navigation.test/admin')
        ->assertOk()
        ->assertInertia(function (AssertableInertia $page): void {
            $navigation = collect($page->toArray()['props']['admin']['navigation']);
            $byLabel = $navigation->keyBy('label');

            expect($byLabel->get('Queues'))->toMatchArray([
                'url' => '/'.config('horizon.path'),
                'external' => true,
            ])
                ->and($byLabel->get('Diagnostics'))->toMatchArray([
                    'url' => '/'.config('pulse.path'),
                    'external' => true,
                ])
                ->and($byLabel->get('Health'))->toMatchArray([
                    'url' => '/admin/health',
                    'external' => true,
                ])
                // Everything else in the console is an Inertia page, and
                // the flag must not leak onto it by accident.
                ->and($navigation->reject(fn (array $item): bool => in_array($item['label'], ['Queues', 'Diagnostics', 'Health'], true))
                    ->every(fn (array $item): bool => $item['external'] === false))->toBeTrue();
        });
});
