<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Konventix\Registration\Actions\RejectRegistration;
use Konventix\Registration\Actions\SubmitRegistration;
use Konventix\Registration\Data\SubmitRegistrationData;
use Konventix\Registration\Enums\RegistrationStatus;
use Konventix\Registration\Events\QuotaReleased;
use Konventix\Registration\Events\RegistrationRejected;
use Konventix\Registration\Exceptions\IllegalRegistrationStateTransitionException;
use Konventix\Registration\Models\Quota;
use Konventix\Registration\Models\RegistrationType;

it('rejects a submitted registration, releasing the quota it held', function (): void {
    $this->withBrand();

    $quota = Quota::factory()->withCapacity(5)->create();
    $type = RegistrationType::factory()->requiringApproval()->create();
    $type->quotas()->attach($quota);

    $registration = (app(SubmitRegistration::class))->handle(
        $type,
        (string) Str::ulid(),
        new SubmitRegistrationData(organizationName: null, billingRecipientName: null, billingEmail: null),
    );

    expect($quota->fresh()->consumed)->toBe(1);

    Event::fake([RegistrationRejected::class, QuotaReleased::class]);

    $rejectorId = (string) Str::ulid();
    $rejected = (new RejectRegistration)->handle($registration, $rejectorId, 'capacity');

    expect($rejected->status)->toBe(RegistrationStatus::Rejected)
        ->and($rejected->rejected_by_user_id)->toBe($rejectorId)
        ->and($rejected->reason_code)->toBe('capacity')
        ->and($quota->fresh()->consumed)->toBe(0);

    Event::assertDispatched(RegistrationRejected::class, fn (RegistrationRejected $event): bool => $event->registrationId === $registration->id
        && $event->reasonCode === 'capacity');

    Event::assertDispatched(QuotaReleased::class, fn (QuotaReleased $event): bool => $event->quotaId === $quota->id
        && $event->releasedUnits === 1);
});

it('refuses to reject a registration that is not Submitted', function (): void {
    $this->withBrand();

    $type = RegistrationType::factory()->create();

    $registration = (app(SubmitRegistration::class))->handle(
        $type,
        (string) Str::ulid(),
        new SubmitRegistrationData(organizationName: null, billingRecipientName: null, billingEmail: null),
    );

    // The default type confirms on submit (on_submit / free), so this is
    // already Confirmed and rejection is no longer legal.
    (new RejectRegistration)->handle($registration, (string) Str::ulid(), 'capacity');
})->throws(IllegalRegistrationStateTransitionException::class);
