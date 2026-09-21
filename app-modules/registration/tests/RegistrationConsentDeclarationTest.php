<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use Konventix\Identity\Models\User;
use Konventix\Registration\Actions\SubmitRegistration;
use Konventix\Registration\Events\RegistrationConsentGiven;
use Konventix\Registration\Models\EntryCondition;
use Konventix\Registration\Models\RegistrationType;

/**
 * Registration's half of the declaration contract (D-CPL-10, spec §6.2):
 * one `RegistrationConsentGiven` per submission, carrying the binding
 * context Compliance will resolve into policy versions.
 *
 * This module builds the bag and never interprets it — it names no policy,
 * reads no Compliance table and does not know whether any text applies. Task
 * 32's `RecordConsentDeclaration` is what consumes it; until then nothing
 * does, and that is deliberate.
 *
 * `Event::fake([...])` with explicit classes, never blanket: a blanket fake
 * swallows the eloquent model events `BelongsToBrand` stamps `brand_id` in
 * (.ai/rules/tests.md).
 */
it('carries the brand, event, type and asserted conditions in the binding context', function (): void {
    $brand = $this->withBrand();

    $condition = EntryCondition::factory()->student()->create();
    $type = RegistrationType::factory()->create();
    $type->entryConditions()->attach($condition->id, ['position' => 0]);

    $user = User::factory()->create();

    Event::fake([RegistrationConsentGiven::class]);

    $registration = app(SubmitRegistration::class)->handle(
        $type,
        (string) $user->getKey(),
        entryConditionSubmission([$condition->id]),
    );

    Event::assertDispatchedTimes(RegistrationConsentGiven::class, 1);
    Event::assertDispatched(
        RegistrationConsentGiven::class,
        fn (RegistrationConsentGiven $event): bool => $event->brandId === $brand->id
            && $event->subjectId === (string) $user->getKey()
            && $event->captureContext === 'registration'
            && $event->bindingContext['event_id'] === $type->event_id
            && $event->bindingContext['registration_type_ids'] === [$type->id]
            && $event->bindingContext['entry_condition_ids'] === [$condition->id]
            && $event->bindingContext['registration_id'] === $registration->id,
    );
});

it('carries an empty condition list where the type demands none', function (): void {
    $this->withBrand();

    $type = RegistrationType::factory()->create();
    $user = User::factory()->create();

    Event::fake([RegistrationConsentGiven::class]);

    app(SubmitRegistration::class)->handle($type, (string) $user->getKey(), emptySubmission());

    Event::assertDispatched(
        RegistrationConsentGiven::class,
        fn (RegistrationConsentGiven $event): bool => $event->bindingContext['entry_condition_ids'] === [],
    );
});
