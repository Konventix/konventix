<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;
use Konventix\Admission\Events\AdmissionCheckedIn;
use Konventix\Compliance\Events\RetentionPeriodElapsed;
use Konventix\Identity\Models\User;
use Konventix\Registration\Actions\SubmitRegistration;
use Konventix\Registration\Enums\EntryConditionAssertionState;
use Konventix\Registration\Enums\EntryConditionDecisionSource;
use Konventix\Registration\Enums\EntryConditionReasonCode;
use Konventix\Registration\Events\RegistrationEntryConditionDecided;
use Konventix\Registration\Listeners\PruneEntryConditionAssertions;
use Konventix\Registration\Models\EntryCondition;
use Konventix\Registration\Models\EntryConditionAssertion;
use Konventix\Registration\Models\RegistrationType;

/**
 * Judging a declaration from the console (ADR-0066 §9.1, D-REG-22) — and
 * the two things that must stay true of every decision here:
 *
 * - it is **advisory**. A refusal records a fact; it does not cancel the
 *   registration, and Task 33's door refusal will not revoke an admission
 *   either (T-8, ADR-0005). A blocking check is `approval: required` on the
 *   type, which ADR-0005 already owns.
 * - the reason is a **bounded code**, never a sentence about a person.
 */
function entryConditionVerificationFixture(): array
{
    $condition = EntryCondition::factory()->student()->create();
    $type = RegistrationType::factory()->create();
    $type->entryConditions()->attach($condition->id, ['position' => 0]);

    $user = User::factory()->create();
    $registration = app(SubmitRegistration::class)->handle(
        $type,
        (string) $user->getKey(),
        entryConditionSubmission([$condition->id]),
    );

    return [$condition, $registration];
}

it('403s a decision without the verify capability', function (): void {
    $this->withBrand();
    [$condition, $registration] = entryConditionVerificationFixture();

    [, $admin] = registrationTypeOperator([
        'platform.access-admin',
        'registration.view-registrations',
    ]);

    $this->actingAs($admin)
        ->post("http://admin.regtypes.test/admin/registrations/{$registration->id}/entry-conditions/{$condition->id}/decide", [
            'state' => 'verified',
        ])
        ->assertForbidden();

    expect(EntryConditionAssertion::withoutBrandScope()->value('state'))
        ->toBe(EntryConditionAssertionState::Asserted);
});

it('verifies a declaration from the console and emits the decision', function (): void {
    $this->withBrand();
    [$condition, $registration] = entryConditionVerificationFixture();

    [, $admin] = registrationTypeOperator([
        'platform.access-admin',
        'registration.view-registrations',
        'registration.verify-entry-conditions',
    ]);

    Event::fake([RegistrationEntryConditionDecided::class]);

    $this->actingAs($admin)
        ->post("http://admin.regtypes.test/admin/registrations/{$registration->id}/entry-conditions/{$condition->id}/decide", [
            'state' => 'verified',
        ])
        ->assertRedirect();

    $assertion = EntryConditionAssertion::withoutBrandScope()->firstOrFail();

    expect($assertion->state)->toBe(EntryConditionAssertionState::Verified)
        ->and($assertion->decided_via)->toBe(EntryConditionDecisionSource::Console)
        ->and($assertion->decided_by_user_id)->toBe((string) $admin->getKey())
        ->and($assertion->decided_at)->not->toBeNull();

    Event::assertDispatched(
        RegistrationEntryConditionDecided::class,
        fn (RegistrationEntryConditionDecided $event): bool => $event->state === 'verified'
            && $event->decidedVia === 'console'
            && $event->entryConditionKey === 'student',
    );
});

it('refuses with a bounded reason and changes nothing about the registration', function (): void {
    $this->withBrand();
    [$condition, $registration] = entryConditionVerificationFixture();
    $statusBefore = $registration->status;

    [, $admin] = registrationTypeOperator([
        'platform.access-admin',
        'registration.view-registrations',
        'registration.verify-entry-conditions',
    ]);

    $this->actingAs($admin)
        ->post("http://admin.regtypes.test/admin/registrations/{$registration->id}/entry-conditions/{$condition->id}/decide", [
            'state' => 'refused',
            'reasonCode' => 'document_missing',
        ])
        ->assertRedirect();

    $assertion = EntryConditionAssertion::withoutBrandScope()->firstOrFail();

    expect($assertion->state)->toBe(EntryConditionAssertionState::Refused)
        ->and($assertion->reason_code)->toBe(EntryConditionReasonCode::DocumentMissing)
        // Advisory: the act itself is untouched — whatever it was before.
        ->and($registration->refresh()->status)->toBe($statusBefore);
});

it('rejects a free-text reason and the asserted state as a decision', function (): void {
    $this->withBrand();
    [$condition, $registration] = entryConditionVerificationFixture();

    [, $admin] = registrationTypeOperator([
        'platform.access-admin',
        'registration.view-registrations',
        'registration.verify-entry-conditions',
    ]);

    $this->actingAs($admin)
        ->post("http://admin.regtypes.test/admin/registrations/{$registration->id}/entry-conditions/{$condition->id}/decide", [
            'state' => 'refused',
            'reasonCode' => 'he looked about thirty',
        ])
        ->assertSessionHasErrors('reasonCode');

    $this->actingAs($admin)
        ->post("http://admin.regtypes.test/admin/registrations/{$registration->id}/entry-conditions/{$condition->id}/decide", [
            'state' => 'asserted',
        ])
        ->assertSessionHasErrors('state');

    expect(EntryConditionAssertion::withoutBrandScope()->value('state'))
        ->toBe(EntryConditionAssertionState::Asserted);
});

it('lists the declaration on the registration detail', function (): void {
    $this->withBrand();
    [$condition, $registration] = entryConditionVerificationFixture();

    [, $admin] = registrationTypeOperator([
        'platform.access-admin',
        'registration.view-registrations',
        'registration.verify-entry-conditions',
    ]);

    $this->actingAs($admin)
        ->get("http://admin.regtypes.test/admin/registrations/{$registration->id}")
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('admin/registrations/Show')
            ->where('canVerifyEntryConditions', true)
            ->has('entryConditions', 1)
            ->where('entryConditions.0.conditionKey', 'student')
            ->where('entryConditions.0.state', 'asserted')
            ->where('entryConditions.0.assertionText', $condition->assertion_text));
});

it('works the asserted pile as a filter on the registrations list', function (): void {
    $this->withBrand();
    [$condition, $registration] = entryConditionVerificationFixture();

    // A second registration that declared nothing at all.
    $plainType = RegistrationType::factory()->create();
    $other = app(SubmitRegistration::class)->handle(
        $plainType,
        (string) User::factory()->create()->getKey(),
        emptySubmission(),
    );

    [, $admin] = registrationTypeOperator([
        'platform.access-admin',
        'registration.view-registrations',
    ]);

    $this->actingAs($admin)
        ->get('http://admin.regtypes.test/admin/registrations?condition=asserted&brand=all&event=all')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->has('registrations.data', 1)
            ->where('registrations.data.0.id', $registration->id));

    $this->actingAs($admin)
        ->get('http://admin.regtypes.test/admin/registrations?condition=none&brand=all&event=all')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->has('registrations.data', 1)
            ->where('registrations.data.0.id', $other->id));
});

/**
 * D-REG-23 / spec §11: a declaration ages on the registration's own clock,
 * started by `EventConcluded` — never on the ten-year bookkeeping floor an
 * invoice carries. Compliance publishes the rule; this module deletes its
 * own rows and ignores every other category.
 */
it('prunes declarations on the registration clock and ignores other categories', function (): void {
    $brand = $this->withBrand();
    [$condition, $registration] = entryConditionVerificationFixture();

    $signal = fn (string $category, ?string $eventId): RetentionPeriodElapsed => new RetentionPeriodElapsed(
        ruleId: (string) Str::ulid(),
        brandId: $brand->id,
        dataCategory: $category,
        cutoffAt: now()->addDay()->toIso8601String(),
        eventId: $eventId,
    );

    $listener = new PruneEntryConditionAssertions;

    // Another module's category, and a record-age signal that names no
    // event: neither touches this table.
    $listener->handle($signal('messaging.delivery-log', $registration->event_id));
    $listener->handle($signal(PruneEntryConditionAssertions::CATEGORY, null));

    expect(EntryConditionAssertion::withoutBrandScope()->count())->toBe(1);

    $listener->handle($signal(PruneEntryConditionAssertions::CATEGORY, $registration->event_id));

    expect(EntryConditionAssertion::withoutBrandScope()->count())->toBe(0)
        // The condition itself is not retention data — it is configuration.
        ->and(EntryCondition::withoutBrandScope()->whereKey($condition->id)->exists())->toBeTrue();
});

/**
 * A scan at a door, as Admission reports it (ADR-0066 §6.4, D-ADM-06). Only
 * the event crosses — this module never sees an `Admission` row, and
 * Admission never sees an assertion.
 */
function doorScanOf(string $registrationId, string $brandId, array $conditionsChecked, string $checkInId = 'check-in-1'): AdmissionCheckedIn
{
    return new AdmissionCheckedIn(
        checkInId: $checkInId,
        admissionId: (string) Str::ulid(),
        registrationId: $registrationId,
        eventId: (string) Str::ulid(),
        brandId: $brandId,
        eventDayId: null,
        checkpointId: null,
        direction: 'in',
        scannedAt: now()->toIso8601String(),
        conditionsChecked: $conditionsChecked,
    );
}

it('verifies a declaration from the door and says where the decision came from', function (): void {
    $this->withBrand();
    [$condition, $registration] = entryConditionVerificationFixture();

    Event::fake([RegistrationEntryConditionDecided::class]);

    event(doorScanOf($registration->id, $registration->brand_id, [
        ['key' => $condition->key, 'outcome' => 'confirmed'],
    ]));

    $assertion = EntryConditionAssertion::withoutBrandScope()->sole();

    expect($assertion->state)->toBe(EntryConditionAssertionState::Verified)
        ->and($assertion->decided_via)->toBe(EntryConditionDecisionSource::Door)
        // Nobody signs in at a door; the device is the actor (ADR-0033).
        ->and($assertion->decided_by_user_id)->toBeNull();

    Event::assertDispatched(RegistrationEntryConditionDecided::class, fn (RegistrationEntryConditionDecided $event): bool => $event->decidedVia === 'door'
        && $event->state === 'verified'
        && $event->entryConditionKey === $condition->key);
});

it('records a door refusal with a bounded code and revokes nothing', function (): void {
    $this->withBrand();
    [$condition, $registration] = entryConditionVerificationFixture();

    $statusBefore = $registration->status;

    event(doorScanOf($registration->id, $registration->brand_id, [
        ['key' => $condition->key, 'outcome' => 'refused'],
    ]));

    $assertion = EntryConditionAssertion::withoutBrandScope()->sole();

    expect($assertion->state)->toBe(EntryConditionAssertionState::Refused)
        // A bounded code, never a sentence about a person (spec §11).
        ->and($assertion->reason_code)->toBe(EntryConditionReasonCode::DocumentMissing)
        // T-8, ADR-0005: the door decides access, not money. The
        // registration is untouched and nothing reaches Commerce.
        ->and($registration->fresh()->status)->toBe($statusBefore)
        ->and($registration->fresh()->cancelled_at)->toBeNull();
});

it('is a no-op when a device replays the same scan', function (): void {
    $this->withBrand();
    [$condition, $registration] = entryConditionVerificationFixture();

    $scan = doorScanOf($registration->id, $registration->brand_id, [
        ['key' => $condition->key, 'outcome' => 'confirmed'],
    ]);

    event($scan);

    $decidedAt = EntryConditionAssertion::withoutBrandScope()->sole()->decided_at;

    Event::fake([RegistrationEntryConditionDecided::class]);

    $this->travel(1)->minutes();

    event($scan);

    Event::assertNotDispatched(RegistrationEntryConditionDecided::class);

    expect(EntryConditionAssertion::withoutBrandScope()->sole()->decided_at->equalTo($decidedAt))->toBeTrue();
});

it('writes nothing for a condition the registration never declared', function (): void {
    // D-REG-19: a condition added to a live type has no row, and a door may
    // not invent a declaration — only an operator's deliberate waive can.
    $this->withBrand();
    [, $registration] = entryConditionVerificationFixture();

    $added = EntryCondition::factory()->create(['key' => 'press', 'name' => 'Press pass']);

    event(doorScanOf($registration->id, $registration->brand_id, [
        ['key' => $added->key, 'outcome' => 'confirmed'],
    ]));

    expect(EntryConditionAssertion::withoutBrandScope()->where('entry_condition_id', $added->getKey())->count())->toBe(0);
});
