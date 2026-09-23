<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use Konventix\Compliance\Models\AuditLogEntry;
use Konventix\Registration\Events\RegistrationConfirmed;
use Konventix\Registration\Events\RegistrationRejected;
use Konventix\Registration\Events\RegistrationStandingAnnounced;
use Konventix\Registration\Events\RegistrationSubmitted;
use Konventix\Registration\Models\Registration;

/**
 * `registration:announce-standing` re-emits the narrow
 * RegistrationStandingAnnounced for every registration, so a read model
 * built after the registrations were written can be backfilled. The first
 * such read model is Competition's entry-eligibility projection (competition
 * design §5.1), which never hears of a registration written without events,
 * such as one the Eventula migration inserts directly. The command
 * deliberately replays none of Submitted, Confirmed and Rejected: the first
 * two drive mail and settlement, and the third is auditable.
 */
it('emits one RegistrationStandingAnnounced per registration carrying its event, registrant and whether it is confirmed now', function (): void {
    $brand = $this->withBrand();
    $confirmed = Registration::factory()->confirmed()->create();
    $cancelled = Registration::factory()->cancelled()->create();
    $rejected = Registration::factory()->rejected()->create();
    $submitted = Registration::factory()->create();

    Event::fake([
        RegistrationStandingAnnounced::class,
        RegistrationSubmitted::class,
        RegistrationConfirmed::class,
        RegistrationRejected::class,
    ]);

    $this->artisan('registration:announce-standing')
        ->expectsOutputToContain('4')
        ->assertSuccessful();

    Event::assertDispatchedTimes(RegistrationStandingAnnounced::class, 4);
    Event::assertDispatched(
        RegistrationStandingAnnounced::class,
        fn (RegistrationStandingAnnounced $event): bool => $event->registrationId === $confirmed->id
            && $event->eventId === $confirmed->event_id
            && $event->brandId === $brand->id
            && $event->registrantUserId === $confirmed->registrant_user_id
            && $event->confirmed === true,
    );

    // Only `confirmed` counts as confirmed; the three others announce false.
    foreach ([$cancelled, $rejected, $submitted] as $registration) {
        Event::assertDispatched(
            RegistrationStandingAnnounced::class,
            fn (RegistrationStandingAnnounced $event): bool => $event->registrationId === $registration->id
                && $event->registrantUserId === $registration->registrant_user_id
                && $event->confirmed === false,
        );
    }

    Event::assertNotDispatched(RegistrationSubmitted::class);
    Event::assertNotDispatched(RegistrationConfirmed::class);
    Event::assertNotDispatched(RegistrationRejected::class);
});

it('writes nothing into the audit trail, which is why it is not a replay of RegistrationRejected', function (): void {
    $this->withBrand();
    Registration::factory()->rejected()->create();

    $this->artisan('registration:announce-standing')->assertSuccessful();

    expect(AuditLogEntry::query()->where('action', 'registration.rejected')->exists())->toBeFalse();
});

it('walks the table in chunks so a large operator does not load every registration at once', function (): void {
    $this->withBrand();
    Registration::factory()->count(3)->create();

    Event::fake([RegistrationStandingAnnounced::class]);

    $this->artisan('registration:announce-standing', ['--chunk' => 2])->assertSuccessful();

    Event::assertDispatchedTimes(RegistrationStandingAnnounced::class, 3);
});
