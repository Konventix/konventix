<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use Inertia\Testing\AssertableInertia;
use Konventix\Identity\Models\User;
use Konventix\Platform\Models\Scopes\BrandScope;
use Konventix\Registration\Actions\SubmitRegistration;
use Konventix\Registration\Enums\EntryConditionAssertionState;
use Konventix\Registration\Events\RegistrationTypeEntryConditionsChanged;
use Konventix\Registration\Models\EntryCondition;
use Konventix\Registration\Models\EntryConditionAssertion;
use Konventix\Registration\Models\Registration;
use Konventix\Registration\Models\RegistrationType;

/**
 * Which declarations a type demands (D-REG-18), and the rule that decides
 * the whole feature: **the set stays editable after the type has sold**
 * (D-REG-19, spec §4.2).
 *
 * `entryConditions` is deliberately outside
 * `GuardsTypeConfiguration::frozenAxes()`, and what makes that safe is that
 * the evidence is the snapshot on each assertion rather than the pivot. So
 * nothing retroactive may follow from an edit: a condition added later
 * writes no row for the registrations that exist, and a condition removed
 * leaves every historic declaration standing.
 */
function entryConditionTypeEditorPayload(RegistrationType $type, array $overrides = []): array
{
    return [
        'eventId' => $type->event_id,
        'name' => $type->name,
        'visibility' => $type->visibility->value,
        'approval' => $type->approval->value,
        'settlement' => $type->settlement->value,
        'confirmationGate' => $type->confirmation_gate->value,
        'termsDays' => $type->terms_days,
        'grants' => $type->grants->toArray(),
        'priceDeclaration' => $type->price_declaration?->toArray(),
        'quotaIds' => [],
        'entryConditionIds' => [],
        ...$overrides,
    ];
}

it('attaches conditions in the posted order and emits the full new set', function (): void {
    $brand = $this->withBrand();
    [, $admin] = registrationTypeOperator([
        'platform.access-admin',
        'registration.manage-registration-types',
    ]);

    $first = EntryCondition::factory()->student()->create();
    $second = EntryCondition::factory()->create(['key' => 'member', 'name' => 'Member']);
    $type = RegistrationType::factory()->create();

    Event::fake([RegistrationTypeEntryConditionsChanged::class]);

    $this->actingAs($admin)
        ->put(
            "http://admin.regtypes.test/admin/registration-types/{$type->id}",
            entryConditionTypeEditorPayload($type, ['entryConditionIds' => [$second->id, $first->id]]),
        )
        ->assertRedirect();

    expect($type->entryConditions()->withoutGlobalScope(BrandScope::class)->pluck('registration_entry_conditions.id')->all())
        ->toBe([$second->id, $first->id]);

    Event::assertDispatched(
        RegistrationTypeEntryConditionsChanged::class,
        fn (RegistrationTypeEntryConditionsChanged $event): bool => $event->entryConditionIds === [$second->id, $first->id]
            && $event->brandId === $brand->id,
    );
});

it('says nothing when a save leaves the set unchanged', function (): void {
    $this->withBrand();
    [, $admin] = registrationTypeOperator([
        'platform.access-admin',
        'registration.manage-registration-types',
    ]);

    $condition = EntryCondition::factory()->student()->create();
    $type = RegistrationType::factory()->create();
    $type->entryConditions()->attach($condition->id, ['position' => 0]);

    Event::fake([RegistrationTypeEntryConditionsChanged::class]);

    $this->actingAs($admin)
        ->put(
            "http://admin.regtypes.test/admin/registration-types/{$type->id}",
            entryConditionTypeEditorPayload($type, ['entryConditionIds' => [$condition->id]]),
        )
        ->assertRedirect();

    Event::assertNotDispatched(RegistrationTypeEntryConditionsChanged::class);
});

it('records the alternative type and refuses one from another event', function (): void {
    $this->withBrand();
    [, $admin] = registrationTypeOperator([
        'platform.access-admin',
        'registration.manage-registration-types',
    ]);

    $type = RegistrationType::factory()->create();
    $sibling = RegistrationType::factory()->create(['event_id' => $type->event_id, 'name' => 'Regular ticket']);
    $stranger = RegistrationType::factory()->create();

    $this->actingAs($admin)
        ->put(
            "http://admin.regtypes.test/admin/registration-types/{$type->id}",
            entryConditionTypeEditorPayload($type, ['alternativeRegistrationTypeId' => $sibling->id]),
        )
        ->assertRedirect();

    expect($type->refresh()->alternative_registration_type_id)->toBe($sibling->id);

    $this->actingAs($admin)
        ->put(
            "http://admin.regtypes.test/admin/registration-types/{$type->id}",
            entryConditionTypeEditorPayload($type, ['alternativeRegistrationTypeId' => $stranger->id]),
        )
        ->assertSessionHasErrors('alternativeRegistrationTypeId');
});

it('accepts a new condition on a sold type and leaves its registrations not asserted', function (): void {
    $this->withBrand();
    [, $admin] = registrationTypeOperator([
        'platform.access-admin',
        'registration.manage-registration-types',
        'registration.view-registrations',
        'registration.verify-entry-conditions',
    ]);

    $type = RegistrationType::factory()->create();
    $user = User::factory()->create();

    // Sold before the condition existed — the terms are frozen, the
    // conditions are not (D-REG-19).
    $registration = app(SubmitRegistration::class)->handle($type, (string) $user->getKey(), emptySubmission());

    $condition = EntryCondition::factory()->student()->create();

    $this->actingAs($admin)
        ->put(
            "http://admin.regtypes.test/admin/registration-types/{$type->id}",
            entryConditionTypeEditorPayload($type, ['entryConditionIds' => [$condition->id]]),
        )
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    expect(EntryConditionAssertion::withoutBrandScope()->count())->toBe(0);

    // The detail says *not asserted* rather than inventing a declaration.
    $this->actingAs($admin)
        ->get("http://admin.regtypes.test/admin/registrations/{$registration->id}")
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('admin/registrations/Show')
            ->has('entryConditions', 1)
            ->where('entryConditions.0.state', null)
            ->where('entryConditions.0.stillRequired', true));

    // And a waive is the honest answer to it.
    $this->actingAs($admin)
        ->post("http://admin.regtypes.test/admin/registrations/{$registration->id}/entry-conditions/{$condition->id}/decide", [
            'state' => 'waived',
        ])
        ->assertRedirect();

    $assertion = EntryConditionAssertion::withoutBrandScope()->firstOrFail();

    expect($assertion->state)->toBe(EntryConditionAssertionState::Waived)
        ->and($assertion->reason_code?->value)->toBe('operator_waived');
});

it('keeps historic declarations when a condition leaves the type', function (): void {
    $this->withBrand();
    [, $admin] = registrationTypeOperator([
        'platform.access-admin',
        'registration.manage-registration-types',
        'registration.view-registrations',
    ]);

    $condition = EntryCondition::factory()->student()->create();
    $type = RegistrationType::factory()->create();
    $type->entryConditions()->attach($condition->id, ['position' => 0]);

    $user = User::factory()->create();
    $registration = app(SubmitRegistration::class)->handle(
        $type,
        (string) $user->getKey(),
        entryConditionSubmission([$condition->id]),
    );

    expect(EntryConditionAssertion::withoutBrandScope()->count())->toBe(1);

    $this->actingAs($admin)
        ->put(
            "http://admin.regtypes.test/admin/registration-types/{$type->id}",
            entryConditionTypeEditorPayload($type, ['entryConditionIds' => []]),
        )
        ->assertRedirect();

    expect(EntryConditionAssertion::withoutBrandScope()->count())->toBe(1);

    $this->actingAs($admin)
        ->get("http://admin.regtypes.test/admin/registrations/{$registration->id}")
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->has('entryConditions', 1)
            ->where('entryConditions.0.state', 'asserted')
            ->where('entryConditions.0.stillRequired', false));
});

it('renders and submits a type with no conditions exactly as before', function (): void {
    $this->withBrand();

    $type = RegistrationType::factory()->create(['name' => 'Community Day']);
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get("/events/{$type->event_id}/register/{$type->id}")
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('registration/Register')
            ->has('entryConditions', 0)
            ->where('alternativeRegistrationTypeId', null));

    $this->actingAs($user)
        ->post("/events/{$type->event_id}/register/{$type->id}", [])
        ->assertRedirect();

    expect(Registration::withoutBrandScope()->count())->toBe(1)
        ->and(EntryConditionAssertion::withoutBrandScope()->count())->toBe(0);
});
