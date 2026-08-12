<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Konventix\Registration\Actions\CancelRegistration;
use Konventix\Registration\Actions\SubmitRegistration;
use Konventix\Registration\Data\SubmitRegistrationData;
use Konventix\Registration\Enums\RegistrationStatus;
use Konventix\Registration\Events\QuotaReleased;
use Konventix\Registration\Events\RegistrationCancelled;
use Konventix\Registration\Models\Quota;
use Konventix\Registration\Models\RegistrationType;

it('cancels a confirmed registration, releasing the quota it held', function (): void {
    $this->withBrand();

    $quota = Quota::factory()->withCapacity(5)->create();
    $type = RegistrationType::factory()->create();
    $type->quotas()->attach($quota);

    $registration = (app(SubmitRegistration::class))->handle(
        $type,
        (string) Str::ulid(),
        new SubmitRegistrationData(organizationName: null, billingRecipientName: null, billingEmail: null),
    );

    expect($registration->status)->toBe(RegistrationStatus::Confirmed)
        ->and($quota->fresh()->consumed)->toBe(1);

    Event::fake([RegistrationCancelled::class, QuotaReleased::class]);

    $cancellerId = (string) Str::ulid();
    $cancelled = (new CancelRegistration)->handle($registration, $cancellerId, 'registrant-withdrew');

    expect($cancelled->status)->toBe(RegistrationStatus::Cancelled)
        ->and($cancelled->cancelled_by_user_id)->toBe($cancellerId)
        ->and($quota->fresh()->consumed)->toBe(0);

    Event::assertDispatched(RegistrationCancelled::class, fn (RegistrationCancelled $event): bool => $event->registrationId === $registration->id);
    Event::assertDispatched(QuotaReleased::class);
});
