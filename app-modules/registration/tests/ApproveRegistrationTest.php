<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Konventix\Registration\Actions\ApproveRegistration;
use Konventix\Registration\Actions\SubmitRegistration;
use Konventix\Registration\Data\SubmitRegistrationData;
use Konventix\Registration\Enums\ConfirmationGate;
use Konventix\Registration\Enums\RegistrationSettlement;
use Konventix\Registration\Enums\RegistrationStatus;
use Konventix\Registration\Events\RegistrationApproved;
use Konventix\Registration\Events\RegistrationConfirmed;
use Konventix\Registration\Exceptions\IllegalRegistrationStateTransitionException;
use Konventix\Registration\Models\Registration;
use Konventix\Registration\Models\RegistrationType;

function submittedRegistration(RegistrationType $type): Registration
{
    return app(SubmitRegistration::class)->handle(
        $type,
        (string) Str::ulid(),
        new SubmitRegistrationData(organizationName: null, billingRecipientName: null, billingEmail: null),
    );
}

it('approves a submitted registration and confirms it when the gate is on_approval', function (): void {
    $this->withBrand();

    $type = RegistrationType::factory()
        ->requiringApproval()
        ->confirmationGate(ConfirmationGate::OnApproval)
        ->settlement(RegistrationSettlement::Free)
        ->create();

    $registration = submittedRegistration($type);

    Event::fake([RegistrationApproved::class, RegistrationConfirmed::class]);

    $approverId = (string) Str::ulid();
    $approved = (app(ApproveRegistration::class))->handle($registration, $approverId);

    expect($approved->status)->toBe(RegistrationStatus::Confirmed)
        ->and($approved->approved_at)->not->toBeNull()
        ->and($approved->approved_by_user_id)->toBe($approverId)
        ->and($approved->confirmed_at)->not->toBeNull();

    Event::assertDispatched(RegistrationApproved::class, fn (RegistrationApproved $event): bool => $event->registrationId === $registration->id
        && $event->approvedByUserId === $approverId);

    Event::assertDispatched(RegistrationConfirmed::class, fn (RegistrationConfirmed $event): bool => $event->registrationId === $registration->id);
});

it('refuses to approve a registration whose type does not require approval', function (): void {
    $this->withBrand();

    $type = RegistrationType::factory()->create();
    $registration = submittedRegistration($type);

    (app(ApproveRegistration::class))->handle($registration, (string) Str::ulid());
})->throws(RuntimeException::class);

it('refuses to approve a registration that is not Submitted', function (): void {
    $this->withBrand();

    $type = RegistrationType::factory()->requiringApproval()->create();
    $registration = submittedRegistration($type);
    $registration->forceFill(['status' => RegistrationStatus::Cancelled])->save();

    (app(ApproveRegistration::class))->handle($registration, (string) Str::ulid());
})->throws(IllegalRegistrationStateTransitionException::class);
