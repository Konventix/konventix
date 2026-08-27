<?php

declare(strict_types=1);

use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;
use Konventix\Commerce\Models\WaitingRoomSettings;
use Konventix\Commerce\WaitingRoom\AdmitFromWaitingRoom;
use Konventix\Commerce\WaitingRoom\TicketCookie;
use Konventix\Commerce\WaitingRoom\WaitingRoomStore;
use Konventix\Identity\Models\User;
use Konventix\Platform\Cookies\CookieRegistry;
use Konventix\Platform\Models\Brand;
use Konventix\Platform\Models\Operator;
use Konventix\Platform\Models\Permission as PermissionModel;
use Konventix\Platform\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * The waiting room (ADR-0047 point 5): a Redis (array, in tests — see
 * phpunit.xml) queue, a signed ticket cookie, and `CommerceWaitingRoomGate`
 * behind `ensure-feature:commerce:shop` + `ensure-waiting-room` on `/shop`.
 * Helper names prefixed `waitingRoom*` — Pest helpers are process-global
 * (.ai/rules/tests.md).
 */
function waitingRoomBrandOperator(Brand $brand): User
{
    $registrar = app(PermissionRegistrar::class);
    $registrar->setPermissionsTeamId($brand->getKey());

    PermissionModel::query()->firstOrCreate(['name' => 'commerce.manage-products', 'guard_name' => 'web']);

    $role = Role::query()->create(['name' => 'Shop Manager', 'guard_name' => 'web', 'brand_id' => $brand->getKey()]);
    $role->givePermissionTo('commerce.manage-products');

    $user = User::factory()->create();
    $user->assignRole($role);

    $registrar->setPermissionsTeamId(null);

    return $user;
}

/**
 * @return array{0: Operator, 1: User}
 */
function waitingRoomConsoleOperator(): array
{
    $operator = Operator::factory()->create();
    $operator->forceFill(['admin_host' => 'admin.waiting-room.test'])->save();

    $registrar = app(PermissionRegistrar::class);
    $registrar->setPermissionsTeamId($operator->getKey());

    foreach (['platform.access-admin', 'platform.view-brands', 'commerce.manage-products'] as $name) {
        PermissionModel::query()->firstOrCreate(['name' => $name, 'guard_name' => 'web']);
    }

    $role = Role::query()->create(['name' => 'Console Admin', 'guard_name' => 'web', 'brand_id' => $operator->getKey()]);
    $role->givePermissionTo(['platform.access-admin', 'platform.view-brands', 'commerce.manage-products']);

    $admin = User::factory()->create();
    $admin->assignRole($role);
    $registrar->setPermissionsTeamId(null);

    return [$operator, $admin];
}

function waitingRoomCookieValue($response, Brand $brand): ?string
{
    return $response->getCookie(TicketCookie::name($brand->getKey()))?->getValue();
}

it('redirects a newcomer to the waiting room and sets the ticket cookie', function (): void {
    $brand = $this->withBrand();
    $this->activateFeature('commerce:shop', $brand);
    $this->activateFeature('commerce:waiting-room', $brand);

    $response = $this->get('/shop');

    $response->assertRedirect('/waiting-room');

    $cookieValue = waitingRoomCookieValue($response, $brand);
    expect($cookieValue)->not->toBeNull()
        ->and(TicketCookie::parse($cookieValue))->not->toBeNull();
});

it('positions tickets 1..n in arrival order', function (): void {
    $brand = $this->withBrand();
    $this->activateFeature('commerce:shop', $brand);
    $this->activateFeature('commerce:waiting-room', $brand);

    $first = $this->get('/shop');
    $second = $this->get('/shop');
    $third = $this->get('/shop');

    $store = app(WaitingRoomStore::class);
    $firstId = TicketCookie::parse(waitingRoomCookieValue($first, $brand));
    $secondId = TicketCookie::parse(waitingRoomCookieValue($second, $brand));
    $thirdId = TicketCookie::parse(waitingRoomCookieValue($third, $brand));

    expect($store->positionOf($brand->getKey(), $firstId))->toBe(1)
        ->and($store->positionOf($brand->getKey(), $secondId))->toBe(2)
        ->and($store->positionOf($brand->getKey(), $thirdId))->toBe(3);
});

it('admits from the head of the queue, letting the admitted ticket through while the next one still waits', function (): void {
    $brand = $this->withBrand();
    $this->activateFeature('commerce:shop', $brand);
    $this->activateFeature('commerce:waiting-room', $brand);

    $first = $this->get('/shop');
    $second = $this->get('/shop');

    $firstCookie = waitingRoomCookieValue($first, $brand);
    $secondCookie = waitingRoomCookieValue($second, $brand);

    $admitted = app(AdmitFromWaitingRoom::class)->handle($brand, 1);
    expect($admitted)->toBe(1);

    $this->withCookie(TicketCookie::name($brand->getKey()), $firstCookie)
        ->get('/shop')
        ->assertOk();

    $this->withCookie(TicketCookie::name($brand->getKey()), $secondCookie)
        ->get('/shop')
        ->assertRedirect('/waiting-room');
});

it('lets an admission expire after the settings TTL', function (): void {
    $brand = $this->withBrand();
    $this->activateFeature('commerce:shop', $brand);
    $this->activateFeature('commerce:waiting-room', $brand);
    WaitingRoomSettings::factory()->create(['admission_ttl_minutes' => 5]);

    $response = $this->get('/shop');
    $cookieValue = waitingRoomCookieValue($response, $brand);

    app(AdmitFromWaitingRoom::class)->handle($brand, 1);

    $this->withCookie(TicketCookie::name($brand->getKey()), $cookieValue)
        ->get('/shop')
        ->assertOk();

    Carbon::setTestNow(now()->addMinutes(6));

    $this->withCookie(TicketCookie::name($brand->getKey()), $cookieValue)
        ->get('/shop')
        ->assertRedirect('/waiting-room');

    Carbon::setTestNow();
});

it('passes through when the waiting room feature is off', function (): void {
    $brand = $this->withBrand();
    $this->activateFeature('commerce:shop', $brand);

    $this->get('/shop')->assertOk();
});

it('ignores a forged cookie and issues a new ticket', function (): void {
    $brand = $this->withBrand();
    $this->activateFeature('commerce:shop', $brand);
    $this->activateFeature('commerce:waiting-room', $brand);

    // Right shape (id.signature), wrong signature.
    $forged = ((string) Str::ulid()).'.0000000000000000000000000000000000000000000000000000000000000000';

    $response = $this->withCookie(TicketCookie::name($brand->getKey()), $forged)->get('/shop');

    $response->assertRedirect('/waiting-room');

    $newTicketId = TicketCookie::parse(waitingRoomCookieValue($response, $brand));
    expect($newTicketId)->not->toBeNull()
        ->and(explode('.', $forged)[0])->not->toBe($newTicketId);
});

it('gives the same signed-in user the same ticket on a second visit', function (): void {
    $brand = $this->withBrand();
    $this->activateFeature('commerce:shop', $brand);
    $this->activateFeature('commerce:waiting-room', $brand);
    $user = User::factory()->create();

    $first = $this->actingAs($user)->get('/shop');
    $firstId = TicketCookie::parse(waitingRoomCookieValue($first, $brand));

    // No cookie carried over — the user mapping alone must resolve to the
    // same ticket.
    $second = $this->actingAs($user)->get('/shop');
    $secondId = TicketCookie::parse(waitingRoomCookieValue($second, $brand));

    expect($firstId)->not->toBeNull()
        ->and($secondId)->toBe($firstId);
});

it('caps new tickets from one IP and refuses the sixth', function (): void {
    $brand = $this->withBrand();
    $this->activateFeature('commerce:shop', $brand);
    $this->activateFeature('commerce:waiting-room', $brand);

    for ($i = 0; $i < 5; $i++) {
        $this->get('/shop')->assertRedirect('/waiting-room');
    }

    $this->get('/shop')->assertStatus(429);
});

it('lets an operator holding commerce.manage-products on this brand bypass the queue', function (): void {
    $brand = $this->withBrand();
    $this->activateFeature('commerce:shop', $brand);
    $this->activateFeature('commerce:waiting-room', $brand);

    $operator = waitingRoomBrandOperator($brand);

    $this->actingAs($operator)->get('/shop')->assertOk();
});

it('declares the ticket cookie', function (): void {
    $declared = collect(app(CookieRegistry::class)->all())->pluck('name');

    expect($declared->contains('waiting_room_*'))->toBeTrue();
});

it('saves the waiting room settings for a brand', function (): void {
    [, $admin] = waitingRoomConsoleOperator();
    $brand = Brand::factory()->create(['slug' => 'wr-settings']);

    $this->actingAs($admin)
        ->put("http://admin.waiting-room.test/admin/brands/{$brand->getKey()}/waiting-room", [
            'admitPerInterval' => 100,
            'admissionTtlMinutes' => 15,
        ])
        ->assertRedirect();

    $settings = WaitingRoomSettings::forBrand($brand->getKey());
    expect($settings->admit_per_interval)->toBe(100)
        ->and($settings->admission_ttl_minutes)->toBe(15);
});

it('validates the waiting room settings bounds', function (): void {
    [, $admin] = waitingRoomConsoleOperator();
    $brand = Brand::factory()->create(['slug' => 'wr-invalid']);

    $this->actingAs($admin)
        ->put("http://admin.waiting-room.test/admin/brands/{$brand->getKey()}/waiting-room", [
            'admitPerInterval' => 0,
            'admissionTtlMinutes' => 500,
        ])
        ->assertSessionHasErrors(['admitPerInterval', 'admissionTtlMinutes']);
});

it('requires commerce.manage-products to save waiting room settings', function (): void {
    $operator = Operator::factory()->create();
    $operator->forceFill(['admin_host' => 'admin.waiting-room-perm.test'])->save();

    $registrar = app(PermissionRegistrar::class);
    $registrar->setPermissionsTeamId($operator->getKey());
    foreach (['platform.access-admin', 'platform.view-brands'] as $name) {
        PermissionModel::query()->firstOrCreate(['name' => $name, 'guard_name' => 'web']);
    }
    $role = Role::query()->create(['name' => 'Viewer', 'guard_name' => 'web', 'brand_id' => $operator->getKey()]);
    $role->givePermissionTo(['platform.access-admin', 'platform.view-brands']);
    $viewer = User::factory()->create();
    $viewer->assignRole($role);
    $registrar->setPermissionsTeamId(null);

    $brand = Brand::factory()->create(['slug' => 'wr-perm']);

    $this->actingAs($viewer)
        ->put("http://admin.waiting-room-perm.test/admin/brands/{$brand->getKey()}/waiting-room", [
            'admitPerInterval' => 100,
            'admissionTtlMinutes' => 15,
        ])
        ->assertForbidden();
});

it('shows the waiting room settings card on the brand edit page only while the feature is active', function (): void {
    [, $admin] = waitingRoomConsoleOperator();
    $brand = Brand::factory()->create(['slug' => 'wr-edit-page']);
    $this->withBrand($brand);
    WaitingRoomSettings::factory()->create(['admit_per_interval' => 77, 'admission_ttl_minutes' => 12]);

    $this->actingAs($admin)
        ->get("http://admin.waiting-room.test/admin/brands/{$brand->getKey()}/edit")
        ->assertInertia(function (AssertableInertia $page): void {
            $feature = collect($page->toArray()['props']['features'])->firstWhere('name', 'commerce:waiting-room');

            expect($feature['settingsComponent'])->toBeNull();
        });

    $this->activateFeature('commerce:waiting-room', $brand);

    $this->actingAs($admin)
        ->get("http://admin.waiting-room.test/admin/brands/{$brand->getKey()}/edit")
        ->assertInertia(function (AssertableInertia $page): void {
            $feature = collect($page->toArray()['props']['features'])->firstWhere('name', 'commerce:waiting-room');

            expect($feature['settingsComponent'])->toBe('commerce/admin/WaitingRoomSettingsCard')
                ->and($feature['settingsProps']['settings']['admitPerInterval'])->toBe(77)
                ->and($feature['settingsProps']['settings']['admissionTtlMinutes'])->toBe(12);
        });
});
