<?php

declare(strict_types=1);

use Inertia\Testing\AssertableInertia;
use Konventix\Identity\Models\User;
use Konventix\Platform\Models\Operator;
use Konventix\Platform\Models\Permission as PermissionModel;
use Konventix\Platform\Models\Role;
use Konventix\Platform\Navigation\NavigationItem;
use Konventix\Platform\Navigation\NavigationRegistry;
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

/**
 * The optional per-item count (D-SUP-13). Platform owns the mechanism;
 * Support is its first user, and `app-modules/support/tests` asserts the
 * number. What matters here is the contract: a closure, resolved at
 * serialisation time and never at registration.
 */
it('leaves an entry without a badge alone', function (): void {
    $item = new NavigationItem(module: 'platform', label: 'Brands', url: '/admin/brands');

    expect($item->badgeCount())->toBeNull()
        ->and($item->toArray())->toMatchArray(['badge' => null]);
});

it('resolves a badge count only when the entry is serialised', function (): void {
    $calls = 0;

    $item = new NavigationItem(
        module: 'platform',
        label: 'Counted',
        url: '/admin/counted',
        badge: function () use (&$calls): int {
            $calls++;

            return $calls;
        },
    );

    // Registering is not counting: the closure must not have run yet.
    app(NavigationRegistry::class)->add($item);

    expect($calls)->toBe(0);

    // Every serialisation asks again, which is what makes the number a fact
    // about the request rather than about the worker (ADR-0003,
    // .ai/rules/providers.md).
    expect($item->toArray()['badge'])->toBe(1)
        ->and($item->toArray()['badge'])->toBe(2);
});

it('renders the badge the registry hands the shell', function (): void {
    $admin = adminNavigationOperator();

    app(NavigationRegistry::class)->add(new NavigationItem(
        module: 'platform',
        label: 'Counted',
        url: '/admin/counted',
        permission: 'platform.view-diagnostics',
        area: NavigationItem::AREA_ADMIN,
        badge: fn (): int => 7,
    ));

    $this->actingAs($admin)
        ->get('http://admin.navigation.test/admin')
        ->assertOk()
        ->assertInertia(function (AssertableInertia $page): void {
            $entry = collect($page->toArray()['props']['admin']['navigation'])
                ->firstWhere('label', 'Counted');

            expect($entry['badge'])->toBe(7);
        });
});
