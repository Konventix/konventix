<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Konventix\Admission\Models\Admission;
use Konventix\Commerce\Models\Order;
use Konventix\Platform\Models\Operator;
use Konventix\Registration\Actions\AmendRegistration;
use Konventix\Registration\Actions\SubmitRegistration;
use Konventix\Registration\Data\GrantData;
use Konventix\Registration\Data\SubmitRegistrationData;
use Konventix\Registration\Enums\ConfirmationGate;
use Konventix\Registration\Enums\GrantKind;
use Konventix\Registration\Enums\RegistrationSettlement;
use Konventix\Registration\Enums\RegistrationStatus;
use Konventix\Registration\Events\RegistrationAmended;
use Konventix\Registration\Events\RegistrationSettlementRequested;
use Konventix\Registration\Exceptions\IllegalRegistrationStateTransitionException;
use Konventix\Registration\Exceptions\QuotaExhaustedException;
use Konventix\Registration\Models\Quota;
use Konventix\Registration\Models\Registration;
use Konventix\Registration\Models\RegistrationAmendment;
use Konventix\Registration\Models\RegistrationType;

/**
 * ADR-0036. Helper names are prefixed because Pest helper functions are
 * process-global across every module's test files (.ai/rules/tests.md).
 */
function amendTestFreeType(): RegistrationType
{
    return RegistrationType::factory()->create([
        'settlement' => RegistrationSettlement::Free,
        'confirmation_gate' => ConfirmationGate::OnSubmit,
        'price_declaration' => null,
    ]);
}

function amendTestSubmit(RegistrationType $type): Registration
{
    return app(SubmitRegistration::class)->handle(
        $type,
        (string) Str::ulid(),
        new SubmitRegistrationData(organizationName: null, billingRecipientName: null, billingEmail: null),
    );
}

/** @return list<GrantData> */
function amendTestCrewGrants(int $quantity = 2): array
{
    return [new GrantData(kind: GrantKind::Admission, role: 'exhibitor_crew', quantity: $quantity)];
}

it('adds grants to a confirmed registration and records the delta', function (): void {
    $this->withBrand();

    $registration = amendTestSubmit(amendTestFreeType());
    expect($registration->status)->toBe(RegistrationStatus::Confirmed);

    Event::fake([RegistrationAmended::class]);

    $amendment = (app(AmendRegistration::class))->handle(
        registration: $registration,
        grants: amendTestCrewGrants(),
        note: 'two extra crew',
    );

    expect($amendment->registration_id)->toBe($registration->id)
        ->and($amendment->lines)->toBeNull()
        ->and($amendment->admissionUnits())->toBe(2);

    Event::assertDispatched(
        RegistrationAmended::class,
        fn (RegistrationAmended $event): bool => $event->registrationId === $registration->id
            && $event->amendmentId === $amendment->id
            && $event->grants === [[
                'kind' => 'admission',
                'role' => 'exhibitor_crew',
                'quantity' => 2,
                'resource_type_id' => null,
            ]],
    );
});

it('refuses to amend a registration that is not confirmed', function (): void {
    $this->withBrand();

    $type = RegistrationType::factory()->requiringApproval()->create([
        'settlement' => RegistrationSettlement::Free,
        'confirmation_gate' => ConfirmationGate::OnApproval,
        'price_declaration' => null,
    ]);

    $registration = amendTestSubmit($type);
    expect($registration->status)->toBe(RegistrationStatus::Submitted);

    expect(fn () => (app(AmendRegistration::class))->handle(
        registration: $registration,
        grants: amendTestCrewGrants(),
    ))->toThrow(IllegalRegistrationStateTransitionException::class);

    expect(RegistrationAmendment::query()->count())->toBe(0);
});

it('rejects an empty delta', function (): void {
    $this->withBrand();

    $registration = amendTestSubmit(amendTestFreeType());

    expect(fn () => (app(AmendRegistration::class))->handle(
        registration: $registration,
        grants: [],
    ))->toThrow(InvalidArgumentException::class);
});

it('consumes one quota unit per added admission', function (): void {
    $this->withBrand();

    $quota = Quota::factory()->withCapacity(10)->create();
    $type = amendTestFreeType();
    $type->quotas()->attach($quota);

    $registration = amendTestSubmit($type);
    expect($quota->fresh()->consumed)->toBe(1);

    (app(AmendRegistration::class))->handle(
        registration: $registration,
        grants: amendTestCrewGrants(3),
    );

    // 1 for the submission + 3 added admissions.
    expect($quota->fresh()->consumed)->toBe(4);
});

it('rejects an amendment that would exceed a quota, consuming nothing', function (): void {
    $this->withBrand();

    $quota = Quota::factory()->withCapacity(2)->create();
    $type = amendTestFreeType();
    $type->quotas()->attach($quota);

    $registration = amendTestSubmit($type);
    expect($quota->fresh()->consumed)->toBe(1);

    expect(fn () => (app(AmendRegistration::class))->handle(
        registration: $registration,
        grants: amendTestCrewGrants(5),
    ))->toThrow(QuotaExhaustedException::class);

    // All-or-nothing: the failed amendment left the ledger untouched and
    // wrote no row.
    expect($quota->fresh()->consumed)->toBe(1)
        ->and(RegistrationAmendment::query()->count())->toBe(0);
});

it('does not request settlement for a free amendment', function (): void {
    $this->withBrand();

    $registration = amendTestSubmit(amendTestFreeType());

    Event::fake([RegistrationSettlementRequested::class]);

    (app(AmendRegistration::class))->handle(
        registration: $registration,
        grants: amendTestCrewGrants(),
    );

    // Crew comes with the package: admissions materialize, no money moves,
    // and the original invoice is never disturbed (ADR-0036).
    Event::assertNotDispatched(RegistrationSettlementRequested::class);
    expect(Order::query()->count())->toBe(0);
});

it('requests settlement keyed on the amendment when the delta is priced', function (): void {
    // The submission below really does settle through Commerce, which needs
    // a legal seller identity on the brand before it can issue an invoice.
    $brand = $this->withBrand();
    $brand->operator()->associate(Operator::factory()->create())->save();

    // QUEUE_CONNECTION=sync in phpunit.xml would otherwise run
    // GenerateInvoiceDocument inline, which resolves its own brand context.
    Queue::fake();

    $type = RegistrationType::factory()
        ->priced(unitAmountNet: '250.00')
        ->create([
            'settlement' => RegistrationSettlement::Invoice,
            'confirmation_gate' => ConfirmationGate::OnSubmit,
            'terms_days' => 30,
        ]);

    // Invoice settlement requires a billing party at submission
    // (SubmitRegistration::guardBillingParty()).
    $registration = (app(SubmitRegistration::class))->handle(
        $type,
        (string) Str::ulid(),
        new SubmitRegistrationData(
            organizationName: 'Musterfirma GmbH',
            billingRecipientName: 'Erika Musterfrau',
            billingEmail: 'rechnung@musterfirma.example',
            billingAddressLine: 'Musterstraße 1',
            billingPostalCode: '18439',
            billingCity: 'Stralsund',
            billingCountryCode: 'DE',
        ),
    );

    Event::fake([RegistrationSettlementRequested::class]);

    $lines = [['label' => 'Extra booth space', 'quantity' => 1, 'unit_amount_net' => '120.00', 'tax_class' => 'standard']];

    $amendment = (app(AmendRegistration::class))->handle(
        registration: $registration,
        grants: [new GrantData(kind: GrantKind::ResourceClaim, role: 'booth', quantity: 1)],
        lines: $lines,
    );

    Event::assertDispatched(
        RegistrationSettlementRequested::class,
        fn (RegistrationSettlementRequested $event): bool => $event->requestKey === $amendment->id
            && $event->registrationId === $registration->id
            && $event->lines === $lines,
    );
});

it('issues exactly the delta admissions and leaves the original ones alone', function (): void {
    $this->withBrand();

    $type = RegistrationType::factory()->create([
        'settlement' => RegistrationSettlement::Free,
        'confirmation_gate' => ConfirmationGate::OnSubmit,
        'price_declaration' => null,
        'grants' => [new GrantData(kind: GrantKind::Admission, role: 'visitor', quantity: 1)],
    ]);

    $registration = amendTestSubmit($type);

    expect(Admission::where('registration_id', $registration->id)->count())->toBe(1);

    $amendment = (app(AmendRegistration::class))->handle(
        registration: $registration,
        grants: amendTestCrewGrants(),
    );

    $admissions = Admission::where('registration_id', $registration->id)->get();

    expect($admissions)->toHaveCount(3)
        ->and($admissions->whereNull('amendment_id'))->toHaveCount(1)
        ->and($admissions->where('amendment_id', $amendment->id))->toHaveCount(2)
        ->and($admissions->where('role', 'exhibitor_crew'))->toHaveCount(2);
});

it('is idempotent: replaying the amendment event issues nothing new', function (): void {
    $this->withBrand();

    $registration = amendTestSubmit(amendTestFreeType());

    $amendment = (app(AmendRegistration::class))->handle(
        registration: $registration,
        grants: amendTestCrewGrants(),
    );

    $before = Admission::where('registration_id', $registration->id)->count();

    event(new RegistrationAmended(
        registrationId: $registration->id,
        amendmentId: $amendment->id,
        registrationTypeId: $registration->registration_type_id,
        eventId: $registration->event_id,
        brandId: $registration->brand_id,
        grants: [['kind' => 'admission', 'role' => 'exhibitor_crew', 'quantity' => 2, 'resource_type_id' => null]],
        amendedAt: now()->toIso8601String(),
    ));

    expect(Admission::where('registration_id', $registration->id)->count())->toBe($before);
});
