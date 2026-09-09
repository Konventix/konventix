<?php

declare(strict_types=1);

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Konventix\Registration\Actions\SubmitRegistration;
use Konventix\Registration\Enums\ConfirmationGate;
use Konventix\Registration\Enums\PaymentStatus;
use Konventix\Registration\Enums\RegistrationSettlement;
use Konventix\Registration\Enums\RegistrationStatus;
use Konventix\Registration\Events\QuotaExhausted;
use Konventix\Registration\Events\RegistrationConfirmed;
use Konventix\Registration\Events\RegistrationSettlementRequested;
use Konventix\Registration\Events\RegistrationSubmitted;
use Konventix\Registration\Exceptions\QuotaExhaustedException;
use Konventix\Registration\Models\Quota;
use Konventix\Registration\Models\Registration;
use Konventix\Registration\Models\RegistrationType;
use Konventix\Registration\Models\WaitlistEntry;

function submitRegistrationAction(): SubmitRegistration
{
    return app(SubmitRegistration::class);
}

it('submits and immediately confirms an on_submit / free registration, emitting both events', function (): void {
    $brand = $this->withBrand();

    $type = RegistrationType::factory()
        ->confirmationGate(ConfirmationGate::OnSubmit)
        ->settlement(RegistrationSettlement::Free)
        ->create();

    Event::fake([RegistrationSubmitted::class, RegistrationConfirmed::class]);

    $registrantId = (string) Str::ulid();

    $registration = submitRegistrationAction()->handle($type, $registrantId, emptySubmission());

    expect($registration->status)->toBe(RegistrationStatus::Confirmed)
        ->and($registration->payment_status)->toBe(PaymentStatus::None)
        ->and($registration->confirmed_at)->not->toBeNull()
        ->and($registration->registrant_user_id)->toBe($registrantId)
        ->and($registration->brand_id)->toBe($brand->id);

    Event::assertDispatched(RegistrationSubmitted::class, fn (RegistrationSubmitted $event): bool => $event->registrationId === $registration->id
        && $event->settlement === 'free'
        && $event->confirmationGate === 'on_submit');

    Event::assertDispatched(RegistrationConfirmed::class, function (RegistrationConfirmed $event) use ($registration, $type): bool {
        expect($event->registrationId)->toBe($registration->id)
            ->and($event->registrationTypeId)->toBe($type->id)
            ->and($event->eventId)->toBe($type->event_id)
            ->and($event->grants)->toHaveCount(1)
            ->and($event->grants[0])->toBe([
                'kind' => 'admission',
                'role' => 'visitor',
                'quantity' => 1,
                'resource_type_id' => null,
            ]);

        return true;
    });
});

it('leaves an on_approval registration Submitted after SubmitRegistration, without confirming', function (): void {
    $this->withBrand();

    $type = RegistrationType::factory()
        ->requiringApproval()
        ->confirmationGate(ConfirmationGate::OnApproval)
        ->settlement(RegistrationSettlement::Free)
        ->create();

    Event::fake([RegistrationSubmitted::class, RegistrationConfirmed::class]);

    $registration = submitRegistrationAction()->handle($type, (string) Str::ulid(), emptySubmission());

    expect($registration->status)->toBe(RegistrationStatus::Submitted)
        ->and($registration->confirmed_at)->toBeNull();

    Event::assertDispatched(RegistrationSubmitted::class);
    Event::assertNotDispatched(RegistrationConfirmed::class);
});

it('rejects submission without an authenticated account (D-REG-08)', function (): void {
    $this->withBrand();

    $type = RegistrationType::factory()->create();

    submitRegistrationAction()->handle($type, null, emptySubmission());
})->throws(RuntimeException::class);

it('rejects submission to a non-public registration type', function (): void {
    $this->withBrand();

    $type = RegistrationType::factory()->inviteOnly()->create();

    submitRegistrationAction()->handle($type, (string) Str::ulid(), emptySubmission());
})->throws(RuntimeException::class);

it('does not create a registration when the account guard fails', function (): void {
    $this->withBrand();

    $type = RegistrationType::factory()->create();

    try {
        submitRegistrationAction()->handle($type, null, emptySubmission());
    } catch (RuntimeException) {
        // expected
    }

    expect(Registration::count())->toBe(0);
});

it('consumes one unit from every quota the type references', function (): void {
    $this->withBrand();

    $quota = Quota::factory()->withCapacity(10)->create();
    $type = RegistrationType::factory()->create();
    $type->quotas()->attach($quota);

    submitRegistrationAction()->handle($type, (string) Str::ulid(), emptySubmission());

    expect($quota->fresh()->consumed)->toBe(1);
});

it('emits QuotaExhausted when the last unit of a quota is consumed', function (): void {
    $this->withBrand();

    $quota = Quota::factory()->withCapacity(1)->create();
    $type = RegistrationType::factory()->create();
    $type->quotas()->attach($quota);

    Event::fake([QuotaExhausted::class]);

    submitRegistrationAction()->handle($type, (string) Str::ulid(), emptySubmission());

    expect($quota->fresh()->consumed)->toBe(1);

    Event::assertDispatched(QuotaExhausted::class, fn (QuotaExhausted $event): bool => $event->quotaId === $quota->id
        && $event->eventId === $type->event_id);
});

it('refuses to submit against an exhausted quota and creates no registration', function (): void {
    $this->withBrand();

    $quota = Quota::factory()->exhausted()->create();
    $type = RegistrationType::factory()->create();
    $type->quotas()->attach($quota);

    expect(fn () => submitRegistrationAction()->handle($type, (string) Str::ulid(), emptySubmission()))
        ->toThrow(QuotaExhaustedException::class);

    expect(Registration::count())->toBe(0)
        ->and($quota->fresh()->consumed)->toBe($quota->capacity);
});

it('confirms an on_submit registration regardless of settlement, requesting settlement at confirmation', function (): void {
    // The gate says confirmation does not wait for payment (ADR-0005): an
    // instant / on_submit type confirms at submission and the settlement
    // request goes out with the confirmation, not before.
    $this->withBrand();

    $type = RegistrationType::factory()
        ->confirmationGate(ConfirmationGate::OnSubmit)
        ->settlement(RegistrationSettlement::Instant)
        ->priced()
        ->create();

    Event::fake([RegistrationSubmitted::class, RegistrationConfirmed::class, RegistrationSettlementRequested::class]);

    $registration = submitRegistrationAction()->handle($type, (string) Str::ulid(), emptySubmission());

    expect($registration->status)->toBe(RegistrationStatus::Confirmed)
        ->and($registration->payment_status)->toBe(PaymentStatus::Pending);
    Event::assertDispatched(RegistrationConfirmed::class);
    Event::assertDispatchedTimes(RegistrationSettlementRequested::class, 1);
});

/**
 * #218 (#216 draft i): the server-side window check, independent of the
 * waiting room and of whatever the landing page happened to render. The
 * landing page already hides a closed type, but a bookmarked URL, a stale
 * tab or a direct POST reaches this Action anyway — and #216 is explicit
 * that the room shapes the queue and is never the access control.
 */
it('refuses a submission before the sale starts, as a form error', function (): void {
    $this->withBrand();

    $type = RegistrationType::factory()->salesWindow(start: '2026-10-01 18:00:00')->create();

    $this->travelTo(Carbon::parse('2026-09-08 12:00:00Z'));

    expect(fn () => submitRegistrationAction()->handle($type, (string) Str::ulid(), emptySubmission()))
        ->toThrow(ValidationException::class);

    expect(Registration::withoutBrandScope()->where('registration_type_id', $type->id)->exists())->toBeFalse();
});

it('refuses a submission after the sale has ended, as a form error', function (): void {
    $this->withBrand();

    $type = RegistrationType::factory()->salesWindow(end: '2026-09-01 18:00:00')->create();

    $this->travelTo(Carbon::parse('2026-09-08 12:00:00Z'));

    try {
        submitRegistrationAction()->handle($type, (string) Str::ulid(), emptySubmission());
        $this->fail('The submission was accepted after the sale ended.');
    } catch (ValidationException $exception) {
        expect($exception->errors())->toHaveKey('registrationType');
    }
});

it('accepts a submission inside the sales window', function (): void {
    $this->withBrand();

    $type = RegistrationType::factory()
        ->salesWindow(start: '2026-09-01 18:00:00', end: '2026-10-01 18:00:00')
        ->create();

    $this->travelTo(Carbon::parse('2026-09-08 12:00:00Z'));

    $registration = submitRegistrationAction()->handle($type, (string) Str::ulid(), emptySubmission());

    expect($registration->status)->toBe(RegistrationStatus::Confirmed);
});

it('lets a waitlist conversion through a window that has since closed', function (): void {
    // A held spot was promised at offer time; the queue draining after the
    // sale ended is the point of the queue (D-REG-12).
    $this->withBrand();

    $type = RegistrationType::factory()->salesWindow(end: '2026-09-01 18:00:00')->create();
    $quota = Quota::factory()->create(['capacity' => 1]);
    $type->quotas()->attach($quota);

    $entry = WaitlistEntry::factory()->create([
        'registration_type_id' => $type->id,
        'event_id' => $type->event_id,
    ]);

    $this->travelTo(Carbon::parse('2026-09-08 12:00:00Z'));

    $registration = submitRegistrationAction()->handle(
        $type,
        (string) Str::ulid(),
        emptySubmission(),
        fromWaitlist: $entry,
    );

    expect($registration->registration_type_id)->toBe($type->id);
});
