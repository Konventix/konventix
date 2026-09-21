<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use Inertia\Testing\AssertableInertia;
use Konventix\Platform\Models\Brand;
use Konventix\Registration\Enums\EntryConditionVerification;
use Konventix\Registration\Events\EntryConditionArchived;
use Konventix\Registration\Events\EntryConditionCreated;
use Konventix\Registration\Models\EntryCondition;
use Konventix\Registration\Models\EntryConditionAssertion;
use Konventix\Registration\Models\RegistrationType;

/**
 * The entry-condition console (ADR-0066 §9.1, D-REG-24): defining what a
 * reduced fare rests on.
 *
 * Its own capability, deliberately not one `registration.desk` holds — the
 * desk judges a declaration, it does not write the terms of the offer.
 * Archive rather than delete is the other claim this file pins: the picker
 * loses the condition, every past declaration keeps it.
 */
it('403s the entry-condition console without the manage capability', function (): void {
    [, $admin] = registrationTypeOperator(['platform.access-admin']);

    $this->actingAs($admin)
        ->get('http://admin.regtypes.test/admin/entry-conditions')
        ->assertForbidden();
});

it('creates a condition with its wording and emits the audited event', function (): void {
    $brand = $this->withBrand();
    [, $admin] = registrationTypeOperator([
        'platform.access-admin',
        'registration.manage-entry-conditions',
    ]);

    Event::fake([EntryConditionCreated::class]);

    $this->actingAs($admin)
        ->post('http://admin.regtypes.test/admin/entry-conditions', [
            'brand_id' => $brand->id,
            'key' => 'student',
            'name' => 'Student ticket',
            'assertionText' => 'I confirm that I am enrolled as a student and will present a valid student ID at the door.',
            'documentHint' => 'Valid student ID or enrolment certificate.',
            'verification' => 'document_at_door',
        ])
        ->assertRedirect();

    $condition = EntryCondition::withoutBrandScope()->firstOrFail();

    expect($condition->key)->toBe('student')
        ->and($condition->brand_id)->toBe($brand->id)
        ->and($condition->event_id)->toBeNull()
        ->and($condition->verification)->toBe(EntryConditionVerification::DocumentAtDoor)
        ->and($condition->assertion_text)->toContain('enrolled as a student');

    Event::assertDispatched(
        EntryConditionCreated::class,
        fn (EntryConditionCreated $event): bool => $event->key === 'student'
            && $event->verification === 'document_at_door',
    );
});

it('refuses a second condition with the same key in one brand', function (): void {
    $brand = $this->withBrand();
    [, $admin] = registrationTypeOperator([
        'platform.access-admin',
        'registration.manage-entry-conditions',
    ]);

    EntryCondition::factory()->student()->create();

    $this->actingAs($admin)
        ->post('http://admin.regtypes.test/admin/entry-conditions', [
            'brand_id' => $brand->id,
            'key' => 'student',
            'name' => 'Another student ticket',
            'assertionText' => 'I confirm something else entirely.',
            'verification' => 'self_declared',
        ])
        ->assertSessionHasErrors('key');

    expect(EntryCondition::withoutBrandScope()->count())->toBe(1);
});

it('lets two brands each have a student condition', function (): void {
    $first = $this->withBrand();
    EntryCondition::factory()->student()->create();

    $second = $this->withBrand(Brand::factory()->create());
    EntryCondition::factory()->student()->create();

    expect(EntryCondition::withoutBrandScope()->where('key', 'student')->count())->toBe(2)
        ->and(EntryCondition::withoutBrandScope()->where('brand_id', $first->id)->count())->toBe(1)
        ->and(EntryCondition::withoutBrandScope()->where('brand_id', $second->id)->count())->toBe(1);
});

it('rewords a condition without touching what anybody already declared', function (): void {
    $this->withBrand();
    [, $admin] = registrationTypeOperator([
        'platform.access-admin',
        'registration.manage-entry-conditions',
    ]);

    $condition = EntryCondition::factory()->student()->create();
    $original = $condition->assertion_text;

    $assertion = EntryConditionAssertion::factory()
        ->assertedFor($condition)
        ->create();

    $this->actingAs($admin)
        ->put("http://admin.regtypes.test/admin/entry-conditions/{$condition->id}", [
            'key' => 'student',
            'name' => 'Student ticket',
            'assertionText' => 'I confirm that I am a student. (reworded)',
            'verification' => 'document_at_door',
        ])
        ->assertRedirect();

    expect($condition->refresh()->assertion_text)->toBe('I confirm that I am a student. (reworded)')
        ->and($assertion->refresh()->assertion_text_snapshot)->toBe($original);
});

it('archives a condition instead of deleting it and hides it from the picker', function (): void {
    $this->withBrand();
    [, $admin] = registrationTypeOperator([
        'platform.access-admin',
        'registration.manage-entry-conditions',
        'registration.manage-registration-types',
    ]);

    $condition = EntryCondition::factory()->student()->create();
    $other = EntryCondition::factory()->create(['name' => 'Still offered']);
    $type = RegistrationType::factory()->create();

    Event::fake([EntryConditionArchived::class]);

    $this->actingAs($admin)
        ->post("http://admin.regtypes.test/admin/entry-conditions/{$condition->id}/archive")
        ->assertRedirect();

    expect(EntryCondition::withoutBrandScope()->count())->toBe(2)
        ->and($condition->refresh()->archived_at)->not->toBeNull();

    Event::assertDispatched(EntryConditionArchived::class);

    // Gone from the type editor's picker; the live one is still offered.
    $this->actingAs($admin)
        ->get("http://admin.regtypes.test/admin/registration-types/{$type->id}/edit")
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->has('entryConditions', 1)
            ->where('entryConditions.0.id', $other->id));
});

it('keeps an archived condition in the picker of a type that still holds it', function (): void {
    $this->withBrand();
    [, $admin] = registrationTypeOperator([
        'platform.access-admin',
        'registration.manage-entry-conditions',
        'registration.manage-registration-types',
    ]);

    $condition = EntryCondition::factory()->student()->archived()->create();
    $type = RegistrationType::factory()->create();
    $type->entryConditions()->attach($condition->id, ['position' => 0]);

    $this->actingAs($admin)
        ->get("http://admin.regtypes.test/admin/registration-types/{$type->id}/edit")
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->has('entryConditions', 1)
            ->where('entryConditions.0.archived', true));
});
