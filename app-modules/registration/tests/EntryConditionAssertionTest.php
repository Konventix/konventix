<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;
use Konventix\Event\Models\Event as EventModel;
use Konventix\Identity\Models\User;
use Konventix\Registration\Actions\SubmitRegistration;
use Konventix\Registration\Enums\EntryConditionAssertionState;
use Konventix\Registration\Enums\EntryConditionVerification;
use Konventix\Registration\Events\RegistrationEntryConditionAsserted;
use Konventix\Registration\Models\EntryCondition;
use Konventix\Registration\Models\EntryConditionAssertion;
use Konventix\Registration\Models\EventSummary;
use Konventix\Registration\Models\Quota;
use Konventix\Registration\Models\Registrant;
use Konventix\Registration\Models\Registration;
use Konventix\Registration\Models\RegistrationType;

/**
 * The declaration itself (ADR-0066 §6.2): what the form shows, what refuses
 * a submission, and what is written down.
 *
 * Three claims this file pins, in the order they matter:
 *
 * - the registrant reads the **verbatim** sentence, not a summary of it;
 * - a box nobody ticked refuses the submission on that box
 *   (`entryConditions.{id}`), with the alternative type beside it where the
 *   operator named one (D-REG-21);
 * - what is stored is a **snapshot**, and a later rewording does not move
 *   it (T-6) — which is the whole reason a condition may be reworded at all.
 */
it('shows one checkbox per condition with the wording verbatim', function (): void {
    $this->withBrand();

    $condition = EntryCondition::factory()->student()->create();
    $type = RegistrationType::factory()->create();
    $type->entryConditions()->attach($condition->id, ['position' => 0]);

    $user = User::factory()->create();

    $this->actingAs($user)
        ->get("/events/{$type->event_id}/register/{$type->id}")
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('registration/Register')
            ->has('entryConditions', 1)
            ->where('entryConditions.0.id', $condition->id)
            ->where('entryConditions.0.assertionText', $condition->assertion_text)
            ->where('entryConditions.0.documentHint', $condition->document_hint)
            // The public shape says nothing about how it is checked or
            // which event it belongs to.
            ->missing('entryConditions.0.verification'));
});

it('refuses a submission that ticked nothing and names the alternative type', function (): void {
    $this->withBrand();

    $condition = EntryCondition::factory()->student()->create();
    $type = RegistrationType::factory()->create();
    $regular = RegistrationType::factory()->create([
        'event_id' => $type->event_id,
        'name' => 'Regular ticket',
    ]);
    $type->entryConditions()->attach($condition->id, ['position' => 0]);
    $type->forceFill(['alternative_registration_type_id' => $regular->id])->save();

    $user = User::factory()->create();

    $this->actingAs($user)
        ->post("/events/{$type->event_id}/register/{$type->id}", [])
        ->assertSessionHasErrors("entryConditions.{$condition->id}");

    expect(Registration::withoutBrandScope()->count())->toBe(0);

    // The link the refusal is rendered beside (D-REG-21) rides on the form.
    $this->actingAs($user)
        ->get("/events/{$type->event_id}/register/{$type->id}")
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('alternativeRegistrationTypeId', $regular->id)
            ->where('alternativeRegistrationTypeName', 'Regular ticket'));
});

it('writes one assertion with the snapshot and emits one event', function (): void {
    $brand = $this->withBrand();

    $condition = EntryCondition::factory()->student()->create();
    $type = RegistrationType::factory()->create();
    $type->entryConditions()->attach($condition->id, ['position' => 0]);

    $user = User::factory()->create();

    Event::fake([RegistrationEntryConditionAsserted::class]);

    $this->actingAs($user)
        ->post("/events/{$type->event_id}/register/{$type->id}", [
            'acceptedEntryConditionIds' => [$condition->id],
        ])
        ->assertRedirect();

    $assertion = EntryConditionAssertion::withoutBrandScope()->firstOrFail();

    expect($assertion->assertion_text_snapshot)->toBe($condition->assertion_text)
        ->and($assertion->verification_snapshot)->toBe(EntryConditionVerification::DocumentAtDoor)
        ->and($assertion->state)->toBe(EntryConditionAssertionState::Asserted)
        ->and($assertion->subject_user_id)->toBe((string) $user->getKey())
        ->and($assertion->brand_id)->toBe($brand->id)
        ->and($assertion->decided_at)->toBeNull();

    Event::assertDispatchedTimes(RegistrationEntryConditionAsserted::class, 1);
    Event::assertDispatched(
        RegistrationEntryConditionAsserted::class,
        fn (RegistrationEntryConditionAsserted $event): bool => $event->entryConditionKey === 'student'
            && $event->verification === 'document_at_door',
    );
});

it('does not move a snapshot when the condition is reworded afterwards', function (): void {
    $this->withBrand();

    $condition = EntryCondition::factory()->student()->create();
    $type = RegistrationType::factory()->create();
    $type->entryConditions()->attach($condition->id, ['position' => 0]);

    $user = User::factory()->create();
    app(SubmitRegistration::class)->handle($type, (string) $user->getKey(), entryConditionSubmission([$condition->id]));

    $original = $condition->assertion_text;
    $condition->forceFill(['assertion_text' => 'Something else entirely.'])->save();

    expect(EntryConditionAssertion::withoutBrandScope()->value('assertion_text_snapshot'))->toBe($original);
});

it('writes exactly one assertion for a submission of three units', function (): void {
    $this->withBrand();

    $condition = EntryCondition::factory()->student()->create();
    $type = RegistrationType::factory()->create(['max_quantity_per_registration' => 5]);
    $type->entryConditions()->attach($condition->id, ['position' => 0]);
    $type->quotas()->attach(Quota::factory()->create(['capacity' => 20])->id);

    $user = User::factory()->create();

    $registration = app(SubmitRegistration::class)->handle(
        $type,
        (string) $user->getKey(),
        entryConditionSubmission([$condition->id], quantity: 3),
    );

    expect($registration->quantity)->toBe(3)
        ->and(EntryConditionAssertion::withoutBrandScope()->count())->toBe(1);
});

/**
 * ADR-0066 §11, purpose limitation: an assertion exists to price a ticket
 * and to check it at the door. It is not a marketing segment, and it must
 * not appear on the attendee list (ADR-0064), on a public profile or in any
 * export that is not the subject's own.
 *
 * Asserted against the props rather than the markup: the attendee page is
 * built from `AttendeeData`, so what it cannot carry is what is not in the
 * payload.
 */
it('keeps a declaration off the public attendee list', function (): void {
    $this->withBrand();

    $event = EventModel::factory()->published()->create();
    EventSummary::query()->create([
        'event_id' => $event->getKey(),
        'name' => $event->name,
        'slug' => $event->slug,
        'visibility' => EventSummary::VISIBILITY_PUBLIC,
        'public_since' => now()->subWeek(),
        'attendee_list_visibility' => 'public',
    ]);

    $condition = EntryCondition::factory()->student()->create();
    $type = RegistrationType::factory()->forEvent($event->getKey())->create();
    $type->entryConditions()->attach($condition->id, ['position' => 0]);

    $registrant = Registrant::query()->create([
        'id' => (string) Str::ulid(),
        'display_name' => 'Ada Student',
        'email' => 'ada@example.test',
        'username' => 'ada',
        'listed' => true,
    ]);

    $user = User::factory()->create();
    $registration = app(SubmitRegistration::class)->handle(
        $type,
        (string) $user->getKey(),
        entryConditionSubmission([$condition->id]),
    );
    $registration->forceFill(['registrant_user_id' => $registrant->getKey()])->save();

    $response = $this->get('/events/'.$event->slug.'/attendees')->assertOk();

    expect(json_encode($response->viewData('page')['props']))
        ->not->toContain('student')
        ->not->toContain($condition->assertion_text);
});
