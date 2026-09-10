<?php

declare(strict_types=1);

use Inertia\Testing\AssertableInertia;
use Konventix\Registration\Models\RegistrationType;
use Konventix\Resources\Enums\CategoryToken;
use Konventix\Resources\Models\ResourceType;

/**
 * Design §7.1 / §9.3 / §11: the type editor renders a seating grant's
 * fields through the `registration.type-editor.seating-fields` slot, which
 * Resources fills with the event's categories — Registration keeps no read
 * model of resource types (ADR-0004) and never imports Resources' models;
 * this test file may, being a test.
 */
function seatingEditorSlot(AssertableInertia $page): ?array
{
    return collect($page->toArray()['props']['seatingSlots'])
        ->firstWhere('component', 'resources/SeatingGrantFields');
}

it('extracted the grants card out of the type editor page', function (): void {
    $card = base_path('app-modules/registration/resources/js/components/RegistrationTypeGrantsCard.vue');
    $page = base_path('app-modules/registration/resources/js/pages/admin/registration-types/Edit.vue');

    expect(file_exists($card))->toBeTrue()
        ->and((string) file_get_contents($card))->toContain('resolveRegisteredComponent')
        ->and((string) file_get_contents($page))->toContain('RegistrationTypeGrantsCard')
        ->and((string) file_get_contents($page))->not->toContain('Add grant');
});

it('offers the event\'s categories through the seating-fields slot when Resources is on for the brand', function (): void {
    $brand = $this->withBrand();
    $this->activateModule('resources', $brand);
    $eventId = registrationTypeEvent($brand);
    [, $admin] = registrationTypeOperator();

    $type = RegistrationType::factory()->create(['event_id' => $eventId]);
    $gamer = ResourceType::factory()->forEvent($eventId)->create(['name' => 'Gamer', 'sort_order' => 2, 'token' => CategoryToken::Chart2, 'symbol' => 'gamepad-2']);
    ResourceType::factory()->forEvent($eventId)->create(['name' => 'Accessible', 'sort_order' => 1]);
    ResourceType::factory()->create(['name' => 'Other event']);

    $this->actingAs($admin)
        ->get("http://admin.regtypes.test/admin/registration-types/{$type->getKey()}/edit")
        ->assertOk()
        ->assertInertia(function (AssertableInertia $page) use ($gamer): void {
            $slot = seatingEditorSlot($page);

            expect($slot)->not->toBeNull()
                ->and($slot['module'])->toBe('resources')
                ->and(collect($slot['props']['categories'])->pluck('name')->all())->toBe(['Accessible', 'Gamer'])
                ->and(collect($slot['props']['categories'])->firstWhere('id', $gamer->getKey()))
                ->toMatchArray(['token' => 'chart-2', 'symbol' => 'gamepad-2']);
        });
});

it('offers no seating fields while Resources is off for the brand', function (): void {
    $brand = $this->withBrand();
    $eventId = registrationTypeEvent($brand);
    [, $admin] = registrationTypeOperator();

    $type = RegistrationType::factory()->create(['event_id' => $eventId]);
    ResourceType::factory()->forEvent($eventId)->create();

    $this->actingAs($admin)
        ->get("http://admin.regtypes.test/admin/registration-types/{$type->getKey()}/edit")
        ->assertInertia(fn (AssertableInertia $page) => $page->has('seatingSlots', 0));
});

it('saves a seating grant and hands it back to the editor', function (): void {
    $brand = $this->withBrand();
    $this->activateModule('resources', $brand);
    $eventId = registrationTypeEvent($brand);
    [, $admin] = registrationTypeOperator();

    $type = RegistrationType::factory()->create(['event_id' => $eventId, 'grants' => []]);
    $category = ResourceType::factory()->forEvent($eventId)->create();

    $this->actingAs($admin)
        ->put("http://admin.regtypes.test/admin/registration-types/{$type->getKey()}", [
            'eventId' => $eventId,
            'name' => $type->name,
            'visibility' => $type->visibility->value,
            'approval' => $type->approval->value,
            'settlement' => 'free',
            'confirmationGate' => 'on_submit',
            'termsDays' => 0,
            'grants' => [
                ['kind' => 'admission', 'role' => 'participant', 'quantity' => 1],
                ['kind' => 'seating', 'role' => 'participant', 'quantity' => 2, 'resourceTypeIds' => [$category->getKey()], 'seatingMode' => 'pick'],
            ],
            'quotaIds' => [],
        ])
        ->assertSessionHasNoErrors()
        ->assertRedirect();

    $this->actingAs($admin)
        ->get("http://admin.regtypes.test/admin/registration-types/{$type->getKey()}/edit")
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('type.grants.1.kind', 'seating')
            ->where('type.grants.1.quantity', 2)
            ->where('type.grants.1.resourceTypeIds', [$category->getKey()])
            ->where('type.grants.1.seatingMode', 'pick'));
});

it('refuses a seating grant and a resource claim on the same category', function (): void {
    $brand = $this->withBrand();
    $eventId = registrationTypeEvent($brand);
    [, $admin] = registrationTypeOperator();

    $type = RegistrationType::factory()->create(['event_id' => $eventId, 'grants' => []]);
    $category = ResourceType::factory()->forEvent($eventId)->create();

    $this->actingAs($admin)
        ->put("http://admin.regtypes.test/admin/registration-types/{$type->getKey()}", [
            'eventId' => $eventId,
            'name' => $type->name,
            'visibility' => $type->visibility->value,
            'approval' => $type->approval->value,
            'settlement' => 'free',
            'confirmationGate' => 'on_submit',
            'termsDays' => 0,
            'grants' => [
                ['kind' => 'seating', 'role' => 'participant', 'quantity' => 1, 'resourceTypeIds' => [$category->getKey()], 'seatingMode' => 'pick'],
                ['kind' => 'resource_claim', 'role' => 'booth', 'quantity' => 1, 'resourceTypeId' => $category->getKey()],
            ],
            'quotaIds' => [],
        ])
        ->assertSessionHasErrors('grants');

    expect($type->refresh()->grants->count())->toBe(0);
});
