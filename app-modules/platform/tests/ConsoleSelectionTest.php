<?php

declare(strict_types=1);

use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;
use Konventix\Identity\Models\User;
use Konventix\Platform\Models\Brand;
use Konventix\Platform\Models\Operator;
use Konventix\Platform\Models\Permission as PermissionModel;
use Konventix\Platform\Models\Role;
use Konventix\Platform\Operators\ConsoleEventSources;
use Spatie\Permission\PermissionRegistrar;

/**
 * The operator picks a brand and an event once, in the shell, and every
 * list that spans brands or events reads that pick as its default filter
 * (ROADMAP second build order, slice 18). Held in the session on the
 * operator host — never in a brand cookie, the console has no brand
 * (ADR-0029).
 */
function selectionOperator(string $host = 'admin.selection.test'): array
{
    $operator = Operator::factory()->create();
    $operator->forceFill(['admin_host' => $host])->save();

    $registrar = app(PermissionRegistrar::class);
    $registrar->setPermissionsTeamId($operator->getKey());

    PermissionModel::query()->firstOrCreate(['name' => 'platform.access-admin', 'guard_name' => 'web']);

    $role = Role::query()->create(['name' => 'Operator Admin', 'guard_name' => 'web', 'brand_id' => $operator->getKey()]);
    $role->givePermissionTo(['platform.access-admin']);

    $admin = User::factory()->create();
    $admin->assignRole($role);

    $registrar->setPermissionsTeamId(null);

    return [$operator, $admin];
}

it('shares an empty selection and the brand list on every console page', function (): void {
    [, $admin] = selectionOperator();
    $brand = Brand::factory()->create(['name' => 'SXLan']);

    $this->actingAs($admin)
        ->get('http://admin.selection.test/admin')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('admin.selection.brandId', null)
            ->where('admin.selection.eventId', null)
            // Contains rather than index 0: the list is alphabetical and the
            // suite's own brand carries a random name.
            ->where('admin.selection.brands', fn (Collection $brands): bool => $brands->contains(
                fn (array $entry): bool => $entry['id'] === $brand->getKey() && $entry['name'] === 'SXLan',
            )));
});

it('remembers the selected brand for the session', function (): void {
    [, $admin] = selectionOperator();
    $brand = Brand::factory()->create();

    $this->actingAs($admin)
        ->put('http://admin.selection.test/admin/selection', ['brandId' => $brand->getKey(), 'eventId' => null])
        ->assertRedirect();

    $this->actingAs($admin)
        ->get('http://admin.selection.test/admin')
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('admin.selection.brandId', $brand->getKey()));
});

it('refuses a brand that does not exist', function (): void {
    [, $admin] = selectionOperator();

    $this->actingAs($admin)
        ->from('http://admin.selection.test/admin')
        ->put('http://admin.selection.test/admin/selection', ['brandId' => '01JZZZZZZZZZZZZZZZZZZZZZZ1'])
        ->assertSessionHasErrors('brandId');
});

it('offers the events a module registered, narrowed to the selected brand', function (): void {
    [, $admin] = selectionOperator();
    $sxlan = Brand::factory()->create(['slug' => 'sxlan']);
    $xpertise = Brand::factory()->create(['slug' => 'xpertise']);
    $sxlanEvent = (string) Str::ulid();
    $xpertiseEvent = (string) Str::ulid();

    app(ConsoleEventSources::class)->add(fn (?string $brandId): array => array_values(array_filter([
        ['id' => $sxlanEvent, 'name' => 'SXLan 2026', 'brandId' => $sxlan->getKey()],
        ['id' => $xpertiseEvent, 'name' => 'Xpertise 2026', 'brandId' => $xpertise->getKey()],
    ], fn (array $event): bool => $brandId === null || $event['brandId'] === $brandId)));

    $this->actingAs($admin)
        ->put('http://admin.selection.test/admin/selection', ['brandId' => $sxlan->getKey()]);

    $this->actingAs($admin)
        ->get('http://admin.selection.test/admin')
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('admin.selection.eventsAvailable', true)
            ->count('admin.selection.events', 1)
            ->where('admin.selection.events.0.name', 'SXLan 2026'));
});

it('remembers an event of the selected brand for the session', function (): void {
    [, $admin] = selectionOperator();
    $sxlan = Brand::factory()->create(['slug' => 'sxlan']);
    $sxlanEvent = (string) Str::ulid();

    app(ConsoleEventSources::class)->add(fn (?string $brandId): array => [
        ['id' => $sxlanEvent, 'name' => 'SXLan 2026', 'brandId' => $sxlan->getKey()],
    ]);

    $this->actingAs($admin)
        ->from('http://admin.selection.test/admin')
        ->put('http://admin.selection.test/admin/selection', [
            'brandId' => $sxlan->getKey(),
            'eventId' => $sxlanEvent,
        ])
        ->assertSessionHasNoErrors()
        ->assertRedirect();

    $this->actingAs($admin)
        ->get('http://admin.selection.test/admin')
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('admin.selection.brandId', $sxlan->getKey())
            ->where('admin.selection.eventId', $sxlanEvent));
});

it('refuses an event no module offers at all', function (): void {
    [, $admin] = selectionOperator();
    $sxlan = Brand::factory()->create(['slug' => 'sxlan']);

    $this->actingAs($admin)
        ->from('http://admin.selection.test/admin')
        ->put('http://admin.selection.test/admin/selection', [
            'brandId' => $sxlan->getKey(),
            'eventId' => (string) Str::ulid(),
        ])
        // The message, not just the key: a shape rule failing would also put
        // an error on `eventId` and hide that this branch never ran.
        ->assertSessionHasErrors(['eventId' => "That event is not one of the selected brand's."]);
});

it('refuses an event that belongs to a different brand than the selected one', function (): void {
    [, $admin] = selectionOperator();
    $sxlan = Brand::factory()->create(['slug' => 'sxlan']);
    $xpertise = Brand::factory()->create(['slug' => 'xpertise']);
    $xpertiseEvent = (string) Str::ulid();

    // A source that ignores the brand it is handed: the brand the event
    // itself names is what has to decide.
    app(ConsoleEventSources::class)->add(fn (?string $brandId): array => [
        ['id' => $xpertiseEvent, 'name' => 'Xpertise 2026', 'brandId' => $xpertise->getKey()],
    ]);

    $this->actingAs($admin)
        ->from('http://admin.selection.test/admin')
        ->put('http://admin.selection.test/admin/selection', [
            'brandId' => $sxlan->getKey(),
            'eventId' => $xpertiseEvent,
        ])
        ->assertSessionHasErrors(['eventId' => "That event is not one of the selected brand's."]);
});

it('has no event selector until a module registers a source', function (): void {
    $sources = new ConsoleEventSources;

    expect($sources->hasAny())->toBeFalse()
        ->and($sources->eventsFor(null))->toBe([]);
});

it('reports no event selector to the shell when no module registered a source', function (): void {
    [, $admin] = selectionOperator();

    // Event's own source is registered at boot in every run, so the empty
    // case only exists if we put an empty registry in its place.
    app()->instance(ConsoleEventSources::class, new ConsoleEventSources);

    $this->actingAs($admin)
        ->get('http://admin.selection.test/admin')
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('admin.selection.eventsAvailable', false)
            ->where('admin.selection.events', []));
});
