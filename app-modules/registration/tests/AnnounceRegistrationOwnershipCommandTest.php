<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use Konventix\Registration\Events\RegistrationOwnershipAnnounced;
use Konventix\Registration\Models\Registration;

/**
 * `registration:announce-ownership` re-emits RegistrationOwnershipAnnounced
 * for every registration so Admission can backfill
 * `admissions.buyer_user_id` on rows written before that column existed
 * (design doc §13, 2026-09-11 amendment "a registration-derived admission
 * is anonymous today"). Same idea as `event:replay-details` and
 * `identity:replay-profiles`. Deliberately not a replay of
 * `RegistrationConfirmed`: that event also drives the confirmation mail,
 * and re-emitting it would re-send every one ever sent.
 */
it('emits one RegistrationOwnershipAnnounced per registration carrying its own registrant', function (): void {
    $brand = $this->withBrand();
    $first = Registration::factory()->confirmed()->create();
    $second = Registration::factory()->confirmed()->create();

    Event::fake([RegistrationOwnershipAnnounced::class]);

    $this->artisan('registration:announce-ownership')
        ->expectsOutputToContain('2')
        ->assertSuccessful();

    Event::assertDispatchedTimes(RegistrationOwnershipAnnounced::class, 2);
    Event::assertDispatched(
        RegistrationOwnershipAnnounced::class,
        fn (RegistrationOwnershipAnnounced $e): bool => $e->registrationId === $first->id
            && $e->brandId === $brand->id
            && $e->registrantUserId === $first->registrant_user_id,
    );
    Event::assertDispatched(
        RegistrationOwnershipAnnounced::class,
        fn (RegistrationOwnershipAnnounced $e): bool => $e->registrationId === $second->id
            && $e->registrantUserId === $second->registrant_user_id,
    );
});

it('walks the table in chunks so a large operator does not load every registration at once', function (): void {
    $this->withBrand();
    Registration::factory()->count(3)->create();

    Event::fake([RegistrationOwnershipAnnounced::class]);

    $this->artisan('registration:announce-ownership', ['--chunk' => 2])->assertSuccessful();

    Event::assertDispatchedTimes(RegistrationOwnershipAnnounced::class, 3);
});
