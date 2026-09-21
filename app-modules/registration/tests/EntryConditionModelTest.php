<?php

declare(strict_types=1);

use Illuminate\Database\QueryException;
use Illuminate\Support\Str;
use Konventix\Registration\Data\EntryConditionData;
use Konventix\Registration\Data\PublicEntryConditionData;
use Konventix\Registration\Enums\EntryConditionAssertionState;
use Konventix\Registration\Enums\EntryConditionDecisionSource;
use Konventix\Registration\Enums\EntryConditionReasonCode;
use Konventix\Registration\Enums\EntryConditionVerification;
use Konventix\Registration\Enums\Permission;
use Konventix\Registration\Models\EntryCondition;
use Konventix\Registration\Models\EntryConditionAssertion;
use Konventix\Registration\Models\Registration;
use Konventix\Registration\Models\RegistrationType;
use Konventix\Registration\Providers\RegistrationServiceProvider;

/**
 * The entry-condition tables, models and enums (ADR-0066, spec §4).
 *
 * Behaviour-neutral by design: nothing here is wired into a form, an action
 * or a page yet — that is Task 31. What is under test is that the shapes the
 * rest of the feature rests on exist and hold the rules the spec states:
 * a key unique per brand, an assertion per `(registration, condition)`
 * whatever the quantity, a snapshot that does not move when the condition is
 * reworded, an archive that keeps the evidence, and a pivot that is not a
 * frozen axis.
 *
 * Helper names prefixed `entryCondition*`: Pest helpers are process-global
 * (`.ai/rules/tests.md`).
 */
function entryConditionTypeWith(EntryCondition ...$conditions): RegistrationType
{
    $type = RegistrationType::factory()->create();

    foreach ($conditions as $position => $condition) {
        $type->entryConditions()->attach($condition, ['position' => $position]);
    }

    return $type;
}

it('stores a brand-wide condition with no event and stamps the brand', function (): void {
    $brand = $this->withBrand();

    $condition = EntryCondition::factory()->student()->create();

    expect($condition->brand_id)->toBe($brand->getKey())
        ->and($condition->event_id)->toBeNull()
        ->and($condition->key)->toBe('student')
        ->and($condition->verification)->toBe(EntryConditionVerification::DocumentAtDoor)
        ->and($condition->assertion_text)->toContain('enrolled as a student')
        ->and($condition->document_hint)->toBe('Valid student ID or enrolment certificate.')
        ->and($condition->isArchived())->toBeFalse();
});

it('pins a one-off condition to a single event', function (): void {
    $this->withBrand();
    $eventId = (string) Str::ulid();

    $condition = EntryCondition::factory()->forEvent($eventId)->create();

    expect($condition->event_id)->toBe($eventId);
});

it('refuses a second condition with the same key for one brand', function (): void {
    $this->withBrand();
    EntryCondition::factory()->create(['key' => 'student']);

    expect(fn () => EntryCondition::factory()->create(['key' => 'student']))
        ->toThrow(QueryException::class);
});

it('hides an archived condition from the active scope without losing it', function (): void {
    $this->withBrand();
    $live = EntryCondition::factory()->create();
    $gone = EntryCondition::factory()->archived()->create();

    expect(EntryCondition::query()->active()->pluck('id')->all())->toBe([$live->getKey()])
        ->and(EntryCondition::query()->archived()->pluck('id')->all())->toBe([$gone->getKey()])
        ->and(EntryCondition::query()->count())->toBe(2)
        ->and($gone->isArchived())->toBeTrue();
});

it('hangs several reusable conditions on a type in the order they render', function (): void {
    $this->withBrand();
    $student = EntryCondition::factory()->student()->create();
    $member = EntryCondition::factory()->create(['key' => 'member', 'name' => 'Member']);

    // Attached out of order on purpose: `position` decides, not insertion.
    $type = RegistrationType::factory()->create();
    $type->entryConditions()->attach($member, ['position' => 1]);
    $type->entryConditions()->attach($student, ['position' => 0]);

    $secondType = entryConditionTypeWith($student);

    expect($type->entryConditions()->pluck('key')->all())->toBe(['student', 'member'])
        // One condition, several types — the reason this is a pivot
        // (D-REG-18).
        ->and($secondType->entryConditions()->pluck('key')->all())->toBe(['student'])
        ->and($student->registrationTypes()->count())->toBe(2);
});

it('lets a type name an alternative to offer instead', function (): void {
    $this->withBrand();
    $regular = RegistrationType::factory()->create(['name' => 'Regular']);
    $student = RegistrationType::factory()->create([
        'name' => 'Student',
        'alternative_registration_type_id' => $regular->getKey(),
    ]);

    expect($student->alternativeType?->getKey())->toBe($regular->getKey())
        // Not an axis: it may be cleared at any time and grants nothing.
        ->and(RegistrationType::factory()->create()->alternative_registration_type_id)->toBeNull();
});

it('writes one assertion per registration and condition, whatever the quantity', function (): void {
    $this->withBrand();
    $condition = EntryCondition::factory()->student()->create();
    $registration = Registration::factory()->create(['quantity' => 3]);

    EntryConditionAssertion::factory()
        ->for($registration)
        ->assertedFor($condition)
        ->create();

    expect($registration->entryConditionAssertions()->count())->toBe(1)
        // The unique index, not just the writer, is what guarantees it
        // (D-REG-20).
        ->and(fn () => EntryConditionAssertion::factory()
            ->for($registration)
            ->assertedFor($condition)
            ->create())
        ->toThrow(QueryException::class);
});

it('keeps the asserted wording when the condition is reworded', function (): void {
    $this->withBrand();
    $condition = EntryCondition::factory()->student()->create();
    $registration = Registration::factory()->create();

    $assertion = EntryConditionAssertion::factory()
        ->for($registration)
        ->assertedFor($condition)
        ->create();

    $original = $assertion->assertion_text_snapshot;

    $condition->forceFill(['assertion_text' => 'Something else entirely.'])->save();

    expect($assertion->fresh()?->assertion_text_snapshot)->toBe($original)
        ->and($original)->not->toBe($condition->fresh()?->assertion_text);
});

it('refuses to delete a condition somebody asserted', function (): void {
    $this->withBrand();
    $condition = EntryCondition::factory()->student()->create();
    EntryConditionAssertion::factory()->assertedFor($condition)->create();

    // Archived, never deleted — the FK restricts (spec §4.2). Nothing is
    // asserted after this: a constraint violation aborts the surrounding
    // transaction on Postgres (`.ai/rules/tests.md`), so archiving is its
    // own test below.
    expect(fn () => $condition->delete())->toThrow(QueryException::class);
});

it('archives a condition that has assertions and keeps them', function (): void {
    $this->withBrand();
    $condition = EntryCondition::factory()->student()->create();
    EntryConditionAssertion::factory()->assertedFor($condition)->create();

    $condition->forceFill(['archived_at' => now()])->save();

    expect($condition->fresh()?->isArchived())->toBeTrue()
        ->and($condition->assertions()->count())->toBe(1)
        ->and(EntryCondition::query()->active()->count())->toBe(0);
});

it('keeps historic assertions when a condition leaves a live type', function (): void {
    $this->withBrand();
    $condition = EntryCondition::factory()->student()->create();
    $type = entryConditionTypeWith($condition);
    $registration = Registration::factory()->for($type)->create();

    EntryConditionAssertion::factory()->for($registration)->assertedFor($condition)->create();

    // The set of conditions is not a frozen axis (D-REG-19): detaching is
    // allowed and rewrites nothing.
    $type->entryConditions()->detach($condition);

    expect($type->fresh()?->entryConditions()->count())->toBe(0)
        ->and($registration->entryConditionAssertions()->count())->toBe(1);
});

it('records a decision with its source and a bounded reason code', function (): void {
    $this->withBrand();
    $condition = EntryCondition::factory()->student()->create();

    $refused = EntryConditionAssertion::factory()
        ->assertedFor($condition)
        ->decided(
            EntryConditionAssertionState::Refused,
            EntryConditionDecisionSource::Door,
            EntryConditionReasonCode::DocumentMissing,
        )
        ->create();

    $waived = EntryConditionAssertion::factory()
        ->assertedFor($condition)
        ->decided(
            EntryConditionAssertionState::Waived,
            EntryConditionDecisionSource::Console,
            EntryConditionReasonCode::OperatorWaived,
        )
        ->create();

    $untouched = EntryConditionAssertion::factory()->assertedFor($condition)->create();

    expect($refused->decided_via)->toBe(EntryConditionDecisionSource::Door)
        ->and($refused->reason_code)->toBe(EntryConditionReasonCode::DocumentMissing)
        ->and($refused->isDecided())->toBeTrue()
        ->and($waived->reason_code)->toBe(EntryConditionReasonCode::OperatorWaived)
        ->and($untouched->isDecided())->toBeFalse()
        ->and($untouched->decided_at)->toBeNull();
});

it('keeps the three verification kinds and no fourth', function (): void {
    expect(array_map(
        fn (EntryConditionVerification $case): string => $case->value,
        EntryConditionVerification::cases(),
    ))->toBe(['self_declared', 'document_at_door', 'operator_checked']);

    expect(array_map(
        fn (EntryConditionAssertionState $case): string => $case->value,
        EntryConditionAssertionState::cases(),
    ))->toBe(['asserted', 'verified', 'refused', 'waived']);

    expect(array_map(
        fn (EntryConditionReasonCode $case): string => $case->value,
        EntryConditionReasonCode::cases(),
    ))->toBe(['document_missing', 'document_invalid', 'operator_waived']);
});

it('owns two capabilities and grants only the judging one to the desk', function (): void {
    $desk = collect((new RegistrationServiceProvider(app()))->defaultRoles())
        ->firstWhere('key', 'registration.desk');

    expect(Permission::ManageEntryConditions->value)->toBe('registration.manage-entry-conditions')
        ->and(Permission::VerifyEntryConditions->value)->toBe('registration.verify-entry-conditions')
        ->and($desk?->permissions)->toContain(Permission::VerifyEntryConditions->value)
        // Defining a fare condition is a product job, not desk work
        // (D-REG-24).
        ->and($desk?->permissions)->not->toContain(Permission::ManageEntryConditions->value);
});

it('shows a registrant the sentence and the hint and nothing else', function (): void {
    $this->withBrand();
    $condition = EntryCondition::factory()->student()->create([
        'description' => 'Internal note for whoever edits a type later.',
    ]);

    $public = PublicEntryConditionData::fromModel($condition)->toArray();
    $console = EntryConditionData::fromModel($condition)->toArray();

    expect(array_keys($public))->toBe(['id', 'key', 'name', 'assertionText', 'documentHint'])
        ->and($public['assertionText'])->toBe($condition->assertion_text)
        // How it is verified, and the operator's own note, stay in the
        // console.
        ->and($console['verification'])->toBe('document_at_door')
        ->and($console['description'])->toBe('Internal note for whoever edits a type later.')
        ->and($console['archived'])->toBeFalse();
});
