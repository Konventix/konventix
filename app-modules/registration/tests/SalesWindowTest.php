<?php

declare(strict_types=1);

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Konventix\Platform\Models\Brand;
use Konventix\Platform\Models\Operator;
use Konventix\Registration\Actions\AmendRegistration;
use Konventix\Registration\Actions\ApproveRegistration;
use Konventix\Registration\Actions\SubmitRegistration;
use Konventix\Registration\Data\GrantData;
use Konventix\Registration\Enums\ConfirmationGate;
use Konventix\Registration\Enums\GrantKind;
use Konventix\Registration\Enums\RegistrationSettlement;
use Konventix\Registration\Enums\RegistrationStatus;
use Konventix\Registration\Events\RegistrationSettlementRequested;
use Konventix\Registration\Models\Registration;
use Konventix\Registration\Models\RegistrationType;
use Konventix\Registration\Models\WaitlistEntry;

/**
 * #218 (#216, draft i): a registration type declares when it is buyable.
 *
 * `sales_start`/`sales_end` are real instants, not wall-clock times: ADR-0027
 * covers the event's own schedule ("doors open 10:00"), while a sales window
 * is a deadline of the same shape as `carts.expires_at` — and this module
 * holds no timezone for the event at all (`registration_event_summaries`
 * carries name, slug and `archived_at` only, ADR-0004).
 *
 * Both columns are nullable and null means "no bound": a type with neither
 * behaves exactly as every type did before this existed.
 */
it('is on sale when it declares no window at all', function (): void {
    $this->withBrand();

    $type = RegistrationType::factory()->create();

    expect($type->sales_start)->toBeNull()
        ->and($type->sales_end)->toBeNull()
        ->and($type->isOnSale())->toBeTrue();
});

it('is not on sale before its start and is on sale from the start onwards', function (): void {
    $this->withBrand();

    $type = RegistrationType::factory()->salesWindow(start: '2026-10-01 18:00:00')->create();

    expect($type->isOnSale(Carbon::parse('2026-10-01 17:59:59Z')))->toBeFalse()
        ->and($type->isOnSale(Carbon::parse('2026-10-01 18:00:00Z')))->toBeTrue()
        ->and($type->isOnSale(Carbon::parse('2026-12-24 12:00:00Z')))->toBeTrue();
});

it('is not on sale once its end has passed', function (): void {
    $this->withBrand();

    $type = RegistrationType::factory()->salesWindow(end: '2026-10-08 18:00:00')->create();

    expect($type->isOnSale(Carbon::parse('2026-10-08 17:59:59Z')))->toBeTrue()
        ->and($type->isOnSale(Carbon::parse('2026-10-08 18:00:00Z')))->toBeFalse();
});

/**
 * Fix round 1, review finding 4: the boundary rule, stated once per module.
 * Its twin is `SalesWindowEnforcementTest`'s "reads the window boundaries as
 * start-inclusive and end-exclusive" in Commerce — the two must agree to the
 * second, because the same window is checked on both sides of the boundary.
 */
it('reads the window boundaries as start-inclusive and end-exclusive', function (): void {
    $this->withBrand();

    $type = RegistrationType::factory()
        ->salesWindow(start: '2026-10-01 18:00:00', end: '2026-10-08 18:00:00')
        ->create();

    expect($type->isOnSale(Carbon::parse('2026-10-01 17:59:59Z')))->toBeFalse()
        ->and($type->isOnSale(Carbon::parse('2026-10-01 18:00:00Z')))->toBeTrue()
        ->and($type->isOnSale(Carbon::parse('2026-10-08 17:59:59Z')))->toBeTrue()
        ->and($type->isOnSale(Carbon::parse('2026-10-08 18:00:00Z')))->toBeFalse();
});

it('drops exactly the same rows from the saleNotOver scope as isOnSale calls over', function (): void {
    // The scope is the SQL half of the one predicate; a boundary second on
    // which the two disagreed would show a type the form then refuses.
    $this->withBrand();

    $ended = RegistrationType::factory()->salesWindow(end: '2026-10-08 18:00:00')->create();
    $ahead = RegistrationType::factory()->salesWindow(start: '2026-10-09 18:00:00')->create();
    $open = RegistrationType::factory()->create();

    $this->travelTo(Carbon::parse('2026-10-08 18:00:00Z'));

    expect(RegistrationType::query()->saleNotOver()->pluck('id')->sort()->values()->all())
        ->toBe(collect([$ahead, $open])->pluck('id')->sort()->values()->all())
        ->and($ended->isOnSale())->toBeFalse();
});

it('defaults to the current time when no clock is passed', function (): void {
    $this->withBrand();

    $type = RegistrationType::factory()->salesWindow(start: '2026-10-01 18:00:00')->create();

    $this->travelTo(Carbon::parse('2026-09-30 12:00:00Z'));
    expect($type->isOnSale())->toBeFalse();

    $this->travelTo(Carbon::parse('2026-10-02 12:00:00Z'));
    expect($type->isOnSale())->toBeTrue();
});

it('round-trips both columns as instants', function (): void {
    $this->withBrand();

    $type = RegistrationType::factory()
        ->salesWindow(start: '2026-10-01 18:00:00', end: '2026-10-08 18:00:00')
        ->create();

    expect($type->fresh()->sales_start?->toIso8601String())->toBe('2026-10-01T18:00:00+00:00')
        ->and($type->fresh()->sales_end?->toIso8601String())->toBe('2026-10-08T18:00:00+00:00');
});

/**
 * Fix round 1, review finding 2. Registration is the authority on who may
 * register; Commerce enforces the window only for a fresh sale. So
 * `RequestSettlement` carries the two scalars only when the request is a
 * fresh registration that must honour the window, and null — "not
 * window-bound" — for a waitlist conversion, a post-approval confirmation
 * and a priced amendment.
 *
 * These run with the real `commerce:shop` listener attached and no
 * `Event::fake()`, so the assertion "no exception" is the point: before this
 * round, each of these paths reached `PlaceRegistrationLineInCart` with a
 * closed window and rolled the registrant's whole submission back with a 500.
 *
 * Helper names prefixed `salesWindow*` — Pest helpers are process-global
 * (.ai/rules/tests.md).
 */
function salesWindowShopBrand(): Brand
{
    $brand = test()->withBrand();
    $brand->operator()->associate(Operator::factory()->create())->save();
    test()->activateFeature('commerce:shop', $brand);

    return $brand;
}

/**
 * Captures the settlement request without faking the dispatcher, so the real
 * Commerce listener still runs beside this one.
 *
 * @return object{event: ?RegistrationSettlementRequested}
 */
function salesWindowSettlementSpy(): object
{
    $spy = new class
    {
        public ?RegistrationSettlementRequested $event = null;
    };

    Event::listen(RegistrationSettlementRequested::class, function (RegistrationSettlementRequested $event) use ($spy): void {
        $spy->event = $event;
    });

    return $spy;
}

it('carries the window on a fresh priced submission', function (): void {
    salesWindowShopBrand();
    $spy = salesWindowSettlementSpy();

    $type = RegistrationType::factory()
        ->settlement(RegistrationSettlement::Instant)
        ->confirmationGate(ConfirmationGate::OnPayment)
        ->priced()
        ->salesWindow(start: '2026-09-01 18:00:00', end: '2026-10-01 18:00:00')
        ->create();

    $this->travelTo(Carbon::parse('2026-09-08 12:00:00Z'));

    app(SubmitRegistration::class)->handle($type, (string) Str::ulid(), emptySubmission());

    expect($spy->event?->saleStartsAt)->toBe('2026-09-01T18:00:00+00:00')
        ->and($spy->event?->saleEndsAt)->toBe('2026-10-01T18:00:00+00:00');
});

it('sends no window for a waitlist conversion after the sale has ended', function (): void {
    salesWindowShopBrand();
    $spy = salesWindowSettlementSpy();

    $type = RegistrationType::factory()
        ->settlement(RegistrationSettlement::Instant)
        ->confirmationGate(ConfirmationGate::OnPayment)
        ->priced()
        ->salesWindow(end: '2026-09-01 18:00:00')
        ->create();

    $entry = WaitlistEntry::factory()->create([
        'registration_type_id' => $type->id,
        'event_id' => $type->event_id,
    ]);

    $this->travelTo(Carbon::parse('2026-09-08 12:00:00Z'));

    $registration = app(SubmitRegistration::class)->handle(
        $type,
        (string) Str::ulid(),
        emptySubmission(),
        fromWaitlist: $entry,
    );

    expect($registration->registration_type_id)->toBe($type->id)
        ->and($spy->event)->not->toBeNull()
        ->and($spy->event?->saleStartsAt)->toBeNull()
        ->and($spy->event?->saleEndsAt)->toBeNull();
});

it('sends no window when an approval confirms a straggler after the sale has ended', function (): void {
    salesWindowShopBrand();

    $type = RegistrationType::factory()
        ->requiringApproval()
        ->settlement(RegistrationSettlement::Instant)
        ->confirmationGate(ConfirmationGate::OnApproval)
        ->priced()
        ->salesWindow(end: '2026-09-08 18:00:00')
        ->create();

    $this->travelTo(Carbon::parse('2026-09-08 12:00:00Z'));

    $registration = app(SubmitRegistration::class)->handle($type, (string) Str::ulid(), emptySubmission());

    // The organizer gets to it the next morning — after the sale closed.
    $this->travelTo(Carbon::parse('2026-09-09 09:00:00Z'));
    $spy = salesWindowSettlementSpy();

    $approved = app(ApproveRegistration::class)->handle($registration, (string) Str::ulid());

    expect($approved->status)->toBe(RegistrationStatus::Confirmed)
        ->and($spy->event)->not->toBeNull()
        ->and($spy->event?->saleStartsAt)->toBeNull()
        ->and($spy->event?->saleEndsAt)->toBeNull();
});

it('sends no window for a priced amendment after the sale has ended', function (): void {
    salesWindowShopBrand();

    $type = RegistrationType::factory()
        ->settlement(RegistrationSettlement::Instant)
        ->confirmationGate(ConfirmationGate::OnSubmit)
        ->priced()
        ->salesWindow(end: '2026-09-08 18:00:00')
        ->create();

    $this->travelTo(Carbon::parse('2026-09-08 12:00:00Z'));

    $registration = app(SubmitRegistration::class)->handle($type, (string) Str::ulid(), emptySubmission());

    $this->travelTo(Carbon::parse('2026-09-09 09:00:00Z'));
    $spy = salesWindowSettlementSpy();

    app(AmendRegistration::class)->handle(
        $registration->refresh(),
        grants: [new GrantData(kind: GrantKind::Admission, role: 'visitor', quantity: 2)],
        lines: [['label' => 'Second badge', 'quantity' => 1, 'unit_amount_net' => '30.00', 'tax_class' => 'standard']],
    );

    expect($spy->event)->not->toBeNull()
        ->and($spy->event?->saleStartsAt)->toBeNull()
        ->and($spy->event?->saleEndsAt)->toBeNull();
});

it('turns a settlement refusal into a form error rather than a 500 when the window closes under the submission', function (): void {
    // The race the Commerce guard exists for: the window is open when
    // SubmitRegistration checks it and closed by the time the synchronous
    // listener builds the cart line. Registration may not import Commerce's
    // exception class (ADR-0004, tests/Unit/ArchitectureTest.php), so the
    // stand-in below is any RuntimeException from a settlement listener —
    // which is exactly what the Action can observe.
    salesWindowShopBrand();

    $type = RegistrationType::factory()
        ->settlement(RegistrationSettlement::Instant)
        ->confirmationGate(ConfirmationGate::OnPayment)
        ->priced()
        ->salesWindow(end: '2026-09-08 12:00:01')
        ->create();

    $this->travelTo(Carbon::parse('2026-09-08 12:00:00Z'));

    Event::listen(RegistrationSettlementRequested::class, function (): void {
        test()->travelTo(Carbon::parse('2026-09-08 13:00:00Z'));

        throw new RuntimeException('Registration is not on sale.');
    });

    try {
        app(SubmitRegistration::class)->handle($type, (string) Str::ulid(), emptySubmission());
        $this->fail('The submission was accepted while its settlement was refused.');
    } catch (ValidationException $exception) {
        expect($exception->errors())->toHaveKey('registrationType');
    }

    expect(Registration::withoutBrandScope()->where('registration_type_id', $type->id)->exists())->toBeFalse();
});
