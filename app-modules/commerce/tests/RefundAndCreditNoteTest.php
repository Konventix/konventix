<?php

declare(strict_types=1);

use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;
use Konventix\Admission\Events\AdmissionCheckedIn;
use Konventix\Commerce\Actions\IssueCreditNote;
use Konventix\Commerce\Actions\RecordPaymentReversal;
use Konventix\Commerce\Actions\RecordPaymentSuccess;
use Konventix\Commerce\Actions\RecordRefund;
use Konventix\Commerce\Actions\RefundOrder;
use Konventix\Commerce\Data\BillingPartyData;
use Konventix\Commerce\Data\CreditNoteLineSelectionData;
use Konventix\Commerce\Enums\OrderStatus;
use Konventix\Commerce\Enums\PaymentStatus;
use Konventix\Commerce\Enums\ReversalKind;
use Konventix\Commerce\Enums\ReversalStatus;
use Konventix\Commerce\Enums\TaxClass;
use Konventix\Commerce\Events\CreditNoteIssued;
use Konventix\Commerce\Events\OrderLineCancelled;
use Konventix\Commerce\Events\OrderRefunded;
use Konventix\Commerce\Exceptions\CreditNoteExceedsInvoiceException;
use Konventix\Commerce\Exceptions\CreditNoteLineSelectionException;
use Konventix\Commerce\Exceptions\CreditNoteRequiresInvoiceException;
use Konventix\Commerce\Exceptions\RefundBlockedByCheckInException;
use Konventix\Commerce\Exceptions\RefundEventRelayFailedException;
use Konventix\Commerce\Listeners\RefundUnitsOnRegistrationQuantityReduced;
use Konventix\Commerce\Models\CheckedInAdmission;
use Konventix\Commerce\Models\CreditNote;
use Konventix\Commerce\Models\CreditNoteLine;
use Konventix\Commerce\Models\Invoice;
use Konventix\Commerce\Models\Order;
use Konventix\Commerce\Models\OrderLine;
use Konventix\Commerce\Models\Payment;
use Konventix\Commerce\Models\PaymentReversal;
use Konventix\Commerce\Models\SettlementAccount;
use Konventix\Commerce\Settlement\SettlementAdapter;
use Konventix\Commerce\Settlement\SettlementAdapterRegistry;
use Konventix\Compliance\Models\AuditLogEntry;
use Konventix\Identity\Models\User;
use Konventix\Messaging\Mail\TemplatedMail;
use Konventix\Platform\Models\Brand;
use Konventix\Platform\Models\Operator;
use Konventix\Registration\Events\RegistrationQuantityReduced;

/**
 * Records refund calls so tests can assert the PSP side of `RefundOrder`
 * (ADR-0039) without any provider SDK.
 */
final class RefundTestSpyAdapter implements SettlementAdapter
{
    /** @var list<array{accountId: string, amount: string, currency: string}> */
    public array $refunds = [];

    public bool $refuse = false;

    /** What the provider answers with (#247); null is `manual`'s answer. */
    public ?string $reference = 're_spy_1';

    public function markOrderPaid(
        string $accountId,
        Order $order,
        string $amountPaid,
        string $currency,
        string $settlementMethod,
    ): Order {
        return $order;
    }

    public function refund(string $accountId, Order $order, string $amount, string $currency): ?string
    {
        if ($this->refuse) {
            throw new RuntimeException('provider refused the refund');
        }

        $this->refunds[] = ['accountId' => $accountId, 'amount' => $amount, 'currency' => $currency];

        // The provider's own identifier for the refund (#247).
        return $this->reference;
    }
}

/**
 * Refunds blocked by check-in (ADR-0037, D-COM-15) and the credit notes that
 * document them (ADR-0031, ADR-0036).
 *
 * Helper names prefixed: Pest helpers are process-global (.ai/rules/tests.md).
 */
function refundTestPaidOrder(?string $registrationId = null, bool $invoiced = true): Order
{
    $order = Order::factory()->paid()->create([
        'settles_type' => 'registration',
        'settles_id' => $registrationId ?? (string) Str::ulid(),
    ]);

    // A credit note corrects an *invoice* (ADR-0031), so most of these cases
    // need one. `$invoiced: false` is the instant-settled order that was paid
    // at checkout and never invoiced.
    if ($invoiced) {
        Invoice::factory()->forOrder($order)->create();
    }

    return $order;
}

function refundTestCheckIn(Order $order, ?string $admissionId = null, string $direction = 'in'): AdmissionCheckedIn
{
    return new AdmissionCheckedIn(
        checkInId: (string) Str::ulid(),
        admissionId: $admissionId ?? (string) Str::ulid(),
        registrationId: $order->settles_id,
        eventId: (string) Str::ulid(),
        brandId: $order->brand_id,
        eventDayId: null,
        checkpointId: null,
        direction: $direction,
        scannedAt: now()->toIso8601String(),
    );
}

it('refunds a paid order and issues a credit note documenting it', function (): void {
    $this->withBrand();

    $order = refundTestPaidOrder();

    Event::fake([OrderRefunded::class, CreditNoteIssued::class]);

    $refunded = app(RefundOrder::class)->handle($order, reasonCode: 'customer-request');

    expect($refunded->status)->toBe(OrderStatus::Refunded)
        ->and(CreditNote::query()->where('order_id', $order->id)->count())->toBe(1);

    Event::assertDispatched(
        OrderRefunded::class,
        fn (OrderRefunded $e): bool => $e->orderId === $order->id
            && $e->settlesId === $order->settles_id
            && $e->creditNoteId !== null
            && $e->whole === true,
    );
    Event::assertDispatched(CreditNoteIssued::class);
});

it('flags a partial refund as not whole, so Registration knows the order still owes', function (): void {
    // ADR-0057 point 5: `OrderRefunded.whole` is the only way Registration
    // can tell a full refund from a partial one — the amount alone cannot.
    $this->withBrand();

    $order = refundTestPaidOrder();
    $partialAmount = bcdiv((string) $order->total_gross, '2', 2);

    Event::fake([OrderRefunded::class, CreditNoteIssued::class]);

    app(RefundOrder::class)->handle($order, reasonCode: 'customer-request', amount: $partialAmount);

    Event::assertDispatched(
        OrderRefunded::class,
        fn (OrderRefunded $e): bool => $e->orderId === $order->id
            && $e->amountRefunded === $partialAmount
            && $e->whole === false,
    );
});

it('blocks the refund when the registration has a checked-in admission', function (): void {
    // D-COM-15, the user's override of the report-only recommendation.
    $this->withBrand();

    $order = refundTestPaidOrder();

    // Arrives the only way it may: through Admission's event, never a join.
    event(refundTestCheckIn($order));

    expect(fn () => app(RefundOrder::class)->handle($order, reasonCode: 'customer-request'))
        ->toThrow(RefundBlockedByCheckInException::class);

    // An explicit refusal, not a silent no-op — nothing moved.
    expect($order->fresh()->status)->toBe(OrderStatus::Paid)
        ->and(CreditNote::query()->where('order_id', $order->id)->count())->toBe(0);
});

it('answers the block from its own read model, never from Admission tables', function (): void {
    $this->withBrand();

    $order = refundTestPaidOrder();
    event(refundTestCheckIn($order));

    expect(CheckedInAdmission::registrationHasCheckIn($order->brand_id, $order->settles_id))->toBeTrue()
        ->and(CheckedInAdmission::query()->where('registration_id', $order->settles_id)->count())->toBe(1);
});

it('converges under replay instead of double-counting', function (): void {
    // An offline device reconciles late (ADR-0033), so redelivery is normal.
    $this->withBrand();

    $order = refundTestPaidOrder();
    $admissionId = (string) Str::ulid();

    event(refundTestCheckIn($order, $admissionId));
    event(refundTestCheckIn($order, $admissionId));

    expect(CheckedInAdmission::query()->where('admission_id', $admissionId)->count())->toBe(1);
});

it('keeps the earliest check-in when events arrive out of order', function (): void {
    $this->withBrand();

    $order = refundTestPaidOrder();
    $admissionId = (string) Str::ulid();

    $late = refundTestCheckIn($order, $admissionId);
    event($late);

    $earlier = new AdmissionCheckedIn(
        checkInId: (string) Str::ulid(),
        admissionId: $admissionId,
        registrationId: $order->settles_id,
        eventId: (string) Str::ulid(),
        brandId: $order->brand_id,
        eventDayId: null,
        checkpointId: null,
        direction: 'in',
        scannedAt: now()->subHours(3)->toIso8601String(),
    );
    event($earlier);

    $row = CheckedInAdmission::query()->where('admission_id', $admissionId)->firstOrFail();

    expect($row->first_checked_in_at->toIso8601String())->toBe(
        now()->subHours(3)->startOfSecond()->toIso8601String(),
    );
});

it('ignores check-outs, which say nothing a check-in has not already said', function (): void {
    $this->withBrand();

    $order = refundTestPaidOrder();
    event(refundTestCheckIn($order, direction: 'out'));

    expect(CheckedInAdmission::query()->count())->toBe(0);

    // So the refund is not blocked by an exit alone.
    expect(app(RefundOrder::class)->handle($order, reasonCode: 'customer-request')->status)
        ->toBe(OrderStatus::Refunded);
});

it('does not block an order that settles something other than a registration', function (): void {
    // Only registrations have admissions; nothing else can be checked in.
    $this->withBrand();

    $order = Order::factory()->paid()->create([
        'settles_type' => 'resource_booking',
        'settles_id' => (string) Str::ulid(),
    ]);
    Invoice::factory()->forOrder($order)->create();

    expect(app(RefundOrder::class)->handle($order, reasonCode: 'customer-request')->status)
        ->toBe(OrderStatus::Refunded);
});

it('refuses to refund an order that was never paid', function (): void {
    $this->withBrand();

    $order = Order::factory()->create(['settles_type' => 'registration']);

    expect(fn () => app(RefundOrder::class)->handle($order, reasonCode: 'customer-request'))
        ->toThrow(RuntimeException::class);
});

it('refunds an unapplied payment on a cancelled order, without a credit note (#6)', function (): void {
    // ADR-0057 point 9: the PSP success that landed after a sweep had
    // already cancelled the order. The money moved and paid for nothing;
    // sending it back is the provider leg alone — there is no document to
    // correct and no sale to undo.
    $this->withBrand();

    $order = Order::factory()->create([
        'status' => OrderStatus::Cancelled,
        'settles_type' => 'cart',
        'total_gross' => '119.00',
    ]);

    $payment = Payment::factory()->succeeded()->create([
        'order_id' => $order->id,
        'provider' => 'spy',
        'settlement_account_id' => (string) Str::ulid(),
        'amount' => '119.00',
        'currency' => $order->currency,
    ]);

    app(SettlementAdapterRegistry::class)->register('spy', RefundTestSpyAdapter::class);
    $spy = app(RefundTestSpyAdapter::class);
    app()->instance(RefundTestSpyAdapter::class, $spy);

    Event::fake([OrderRefunded::class, CreditNoteIssued::class]);

    app(RefundOrder::class)->handle($order, reasonCode: 'customer-request');

    $reversal = PaymentReversal::withoutBrandScope()->where('order_id', $order->id)->sole();

    expect($spy->refunds)->toHaveCount(1)
        ->and($reversal->kind)->toBe(ReversalKind::Refund)
        ->and($reversal->provider_reference)->toBe('re_spy_1')
        // The order is not "refunded": nothing was sold to undo.
        ->and($order->refresh()->status)->toBe(OrderStatus::Cancelled)
        ->and(CreditNote::withoutBrandScope()->where('order_id', $order->id)->count())->toBe(0)
        // And the money leaves the console's "decide about this" list.
        ->and($payment->refresh()->status)->toBe(PaymentStatus::Reversed)
        ->and(Payment::withoutBrandScope()->unapplied()->where('order_id', $order->id)->count())->toBe(0);

    Event::assertNotDispatched(OrderRefunded::class);
    Event::assertNotDispatched(CreditNoteIssued::class);
});

/**
 * #250: the `commerce.order.refunded` audit entry used to be written by
 * `OrderAdminController::refund()`, so a refund from anywhere else — the
 * reconcile sweep, a console command, a job — moved real money and left no
 * trail at all. It is the Action's now, and this is the caller that has no
 * controller and no authenticated user behind it.
 */
it('writes the refund audit entry for a caller that is not the console (#250)', function (): void {
    $this->withBrand();

    $order = refundTestPaidOrder();

    app(RefundOrder::class)->handle(
        $order,
        reasonCode: 'customer-request',
        reasonNote: 'Reconciled against the statement.',
    );

    $audit = AuditLogEntry::query()->where('action', 'commerce.order.refunded')->sole();

    expect($audit->subject_id)->toBe($order->id)
        ->and($audit->brand_id)->toBe($order->brand_id)
        // A sweep has no actor, and inventing one would make the trail lie
        // (`AuditRecorder`'s own docblock).
        ->and($audit->actor_user_id)->toBeNull()
        ->and($audit->context)->toBe([
            'reason_code' => 'customer-request',
            'reason_note' => 'Reconciled against the statement.',
            'amount' => (string) $order->total_gross,
            'currency' => $order->currency,
            'provider_reference' => null,
            'unapplied' => false,
            'announcement_failed' => false,
        ]);
});

/**
 * D-0.8.2-3 / #250: a manual unapplied refund — the order cancelled, the
 * payment succeeded, the organizer wiring the money back outside the
 * platform — wrote no `payment_reversals` row at all, because
 * `provider_reference` is NOT NULL and no provider had named this money.
 * The payment left the unapplied list all the same, so reconciliation saw
 * the money vanish. The row now carries a synthetic reference instead.
 */
it('writes a reversal row with a synthetic reference for a manual unapplied refund (#250)', function (): void {
    $this->withBrand();

    $order = Order::factory()->create([
        'status' => OrderStatus::Cancelled,
        'settles_type' => 'cart',
        'total_gross' => '119.00',
    ]);

    // `manual`: `RefundOrder`'s PSP call resolves `ManualSettlementAdapter`,
    // which is a true no-op and answers with no reference (ADR-0039).
    $payment = Payment::factory()->succeeded()->create([
        'order_id' => $order->id,
        'provider' => 'manual',
        'amount' => '119.00',
        'currency' => $order->currency,
    ]);

    app(RefundOrder::class)->handle($order, reasonCode: 'customer-request');

    $reversal = PaymentReversal::withoutBrandScope()->where('order_id', $order->id)->sole();

    expect($reversal->provider_reference)->toBe('manual:'.$order->id)
        ->and($reversal->provider_reference)->toBe(RefundOrder::manualReference($order))
        // The colon is the whole collision argument: every reference the
        // three providers assign is `[A-Za-z0-9_-]` only — Stripe's `re_…`,
        // Mollie's `re_…`/`chb_…`, Unzer's `s-cnl-1`.
        ->and($reversal->provider_reference)->toContain(':')
        ->and($reversal->kind)->toBe(ReversalKind::Refund)
        ->and($reversal->status)->toBe(ReversalStatus::Final)
        ->and($reversal->amount)->toBe('119.00')
        ->and($reversal->provider)->toBe('manual')
        // Unchanged around it: no document to correct, the order stays
        // cancelled, and the money leaves the unapplied list (#6).
        ->and($order->refresh()->status)->toBe(OrderStatus::Cancelled)
        ->and(CreditNote::withoutBrandScope()->where('order_id', $order->id)->count())->toBe(0)
        ->and($payment->refresh()->status)->toBe(PaymentStatus::Reversed);

    // And the trail names the same identifier the row does, so an operator
    // reading the audit entry can find the row (#247's shape, manual case).
    $audit = AuditLogEntry::query()->where('action', 'commerce.order.refunded')->sole();

    expect($audit->context['provider_reference'])->toBe('manual:'.$order->id)
        ->and($audit->context['unapplied'])->toBeTrue();
});

it('converges on one row when a manual unapplied refund runs a second time (#250)', function (): void {
    // The unique `(provider, provider_reference)` key is still the whole
    // convergence mechanism, which is why the synthetic reference had to be
    // deterministic rather than random: a replayed job, or a sweep that reads
    // the payment before the first run's commit is visible to it, must find
    // this row rather than write a second one.
    $this->withBrand();

    $order = Order::factory()->create([
        'status' => OrderStatus::Cancelled,
        'total_gross' => '119.00',
    ]);

    $payment = Payment::factory()->succeeded()->create([
        'order_id' => $order->id,
        'provider' => 'manual',
        'amount' => '119.00',
        'currency' => $order->currency,
    ]);

    app(RefundOrder::class)->handle($order, reasonCode: 'customer-request');

    $first = PaymentReversal::withoutBrandScope()->where('order_id', $order->id)->sole();

    // The replay: the payment is back on the unapplied list as far as this
    // caller can see, so the whole path runs again from the top.
    $payment->refresh()->forceFill(['status' => PaymentStatus::Succeeded])->save();

    app(RefundOrder::class)->handle($order->refresh(), reasonCode: 'customer-request');

    $second = PaymentReversal::withoutBrandScope()->where('order_id', $order->id)->sole();

    expect($second->id)->toBe($first->id)
        ->and($second->amount)->toBe('119.00')
        ->and($payment->refresh()->status)->toBe(PaymentStatus::Reversed);
});

it('refuses a second refund of unapplied money the first one already sent back (#6)', function (): void {
    $this->withBrand();

    $order = Order::factory()->create(['status' => OrderStatus::Cancelled, 'total_gross' => '119.00']);

    Payment::factory()->succeeded()->create([
        'order_id' => $order->id,
        'provider' => 'spy',
        'amount' => '119.00',
        'currency' => $order->currency,
    ]);

    app(SettlementAdapterRegistry::class)->register('spy', RefundTestSpyAdapter::class);
    app()->instance(RefundTestSpyAdapter::class, app(RefundTestSpyAdapter::class));

    app(RefundOrder::class)->handle($order, reasonCode: 'customer-request');

    // The payment is `reversed` now, so there is no unapplied money left and
    // the guard refuses — the operator pressing the button twice cannot send
    // the money back twice.
    expect(fn () => app(RefundOrder::class)->handle($order->refresh(), reasonCode: 'customer-request'))
        ->toThrow(RuntimeException::class);
});

it('draws credit-note numbers from their own gapless series', function (): void {
    // A separate sequence from invoices, so a tax advisor can tell the two
    // document kinds apart by the number alone.
    $this->withBrand();

    $first = app(IssueCreditNote::class)->handle(refundTestPaidOrder(), IssueCreditNote::REASON_REFUND);
    $second = app(IssueCreditNote::class)->handle(refundTestPaidOrder(), IssueCreditNote::REASON_TRANSFER);

    expect($first->credit_note_number)->toStartWith('GS-')
        ->and($first->credit_note_number)->toEndWith('-00001')
        ->and($second->credit_note_number)->toEndWith('-00002');
});

it('marks a transfer credit note distinctly from a refund one', function (): void {
    // ADR-0036's post-invoice transfer issues the document without money
    // moving; a consumer counting refunds must be able to tell them apart.
    $this->withBrand();

    Event::fake([CreditNoteIssued::class, OrderRefunded::class]);

    app(IssueCreditNote::class)->handle(refundTestPaidOrder(), IssueCreditNote::REASON_TRANSFER);

    Event::assertDispatched(
        CreditNoteIssued::class,
        fn (CreditNoteIssued $e): bool => $e->reasonCode === IssueCreditNote::REASON_TRANSFER,
    );
    Event::assertNotDispatched(OrderRefunded::class);
});

it('refunds an uninvoiced order without producing paperwork', function (): void {
    // An instant-settled order paid at checkout has no invoice, so there is
    // nothing for a credit note to correct — the money still goes back.
    $this->withBrand();

    $order = refundTestPaidOrder(invoiced: false);

    Event::fake([OrderRefunded::class, CreditNoteIssued::class]);

    expect(app(RefundOrder::class)->handle($order, reasonCode: 'customer-request')->status)
        ->toBe(OrderStatus::Refunded)
        ->and(CreditNote::query()->where('order_id', $order->id)->count())->toBe(0);

    Event::assertDispatched(OrderRefunded::class, fn (OrderRefunded $e): bool => $e->creditNoteId === null);
    Event::assertNotDispatched(CreditNoteIssued::class);
});

it('blocks the refund of a cart order when one of its registration lines is checked in', function (): void {
    // ADR-0047: a cart order settles a mix of products and registrations —
    // the check-in guard has to look through settles_kind lines, not just
    // settles_id, for a cart order to be covered at all.
    test()->withBrand();

    $registrationId = (string) Str::ulid();
    $order = Order::factory()->paid()->create([
        'settles_type' => 'cart',
        'settles_id' => (string) Str::ulid(),
    ]);
    OrderLine::factory()->forOrder($order)->create([
        'settles_kind' => 'product',
        'settles_ref' => (string) Str::ulid(),
    ]);
    OrderLine::factory()->forOrder($order)->create([
        'position' => 2,
        'settles_kind' => 'registration',
        'settles_ref' => $registrationId,
    ]);
    Invoice::factory()->forOrder($order)->create();

    event(new AdmissionCheckedIn(
        checkInId: (string) Str::ulid(),
        admissionId: (string) Str::ulid(),
        registrationId: $registrationId,
        eventId: (string) Str::ulid(),
        brandId: $order->brand_id,
        eventDayId: null,
        checkpointId: null,
        direction: 'in',
        scannedAt: now()->toIso8601String(),
    ));

    expect(fn () => app(RefundOrder::class)->handle($order, reasonCode: 'customer-request'))
        ->toThrow(RefundBlockedByCheckInException::class);

    expect($order->fresh()->status)->toBe(OrderStatus::Paid)
        ->and(CreditNote::query()->where('order_id', $order->id)->count())->toBe(0);
});

it('refunds a cart order whose lines have no checked-in registration', function (): void {
    test()->withBrand();

    $order = Order::factory()->paid()->create([
        'settles_type' => 'cart',
        'settles_id' => (string) Str::ulid(),
    ]);
    OrderLine::factory()->forOrder($order)->create([
        'settles_kind' => 'product',
        'settles_ref' => (string) Str::ulid(),
    ]);
    OrderLine::factory()->forOrder($order)->create([
        'position' => 2,
        'settles_kind' => 'registration',
        'settles_ref' => (string) Str::ulid(),
    ]);
    Invoice::factory()->forOrder($order)->create();

    Event::fake([OrderRefunded::class, CreditNoteIssued::class]);

    expect(app(RefundOrder::class)->handle($order, reasonCode: 'customer-request')->status)
        ->toBe(OrderStatus::Refunded);
});

it('refuses to credit-note an order that was never invoiced', function (): void {
    $this->withBrand();

    expect(fn () => app(IssueCreditNote::class)->handle(
        refundTestPaidOrder(invoiced: false),
        IssueCreditNote::REASON_REFUND,
    ))->toThrow(CreditNoteRequiresInvoiceException::class);
});

it('sends the PSP refund through the provider that took the payment', function (): void {
    // ADR-0039: the money goes back via the order's settled payment, not the
    // brand's *current* live account, which may have changed since.
    $this->withBrand();

    $order = refundTestPaidOrder();
    $accountId = (string) Str::ulid();

    Payment::factory()->succeeded()->create([
        'order_id' => $order->id,
        'provider' => 'spy',
        'settlement_account_id' => $accountId,
        'amount' => $order->total_gross,
        'currency' => $order->currency,
    ]);

    app(SettlementAdapterRegistry::class)->register('spy', RefundTestSpyAdapter::class);
    $spy = app(RefundTestSpyAdapter::class);
    app()->instance(RefundTestSpyAdapter::class, $spy);

    Event::fake([OrderRefunded::class, CreditNoteIssued::class]);

    app(RefundOrder::class)->handle($order, reasonCode: 'customer-request');

    expect($spy->refunds)->toHaveCount(1)
        ->and($spy->refunds[0]['accountId'])->toBe($accountId)
        ->and($spy->refunds[0]['amount'])->toBe((string) $order->total_gross);
});

it('records the provider\'s own reference for the refund on a reversal row (#247)', function (): void {
    // Before this the `re_…` never left the adapter: after a refund no path
    // led from the order to the money's identifier at the provider, and the
    // "already refunded" branch of #236 could only tell the operator to go
    // and look in the dashboard.
    $this->withBrand();

    $order = refundTestPaidOrder();
    $accountId = (string) Str::ulid();

    $payment = Payment::factory()->succeeded()->create([
        'order_id' => $order->id,
        'provider' => 'spy',
        'settlement_account_id' => $accountId,
        'amount' => $order->total_gross,
        'currency' => $order->currency,
    ]);

    app(SettlementAdapterRegistry::class)->register('spy', RefundTestSpyAdapter::class);
    app()->instance(RefundTestSpyAdapter::class, app(RefundTestSpyAdapter::class));

    Event::fake([OrderRefunded::class, CreditNoteIssued::class]);

    app(RefundOrder::class)->handle($order, reasonCode: 'customer-request');

    $reversal = PaymentReversal::withoutBrandScope()->where('order_id', $order->id)->sole();

    expect($reversal->kind)->toBe(ReversalKind::Refund)
        ->and($reversal->status)->toBe(ReversalStatus::Final)
        ->and($reversal->provider_reference)->toBe('re_spy_1')
        ->and($reversal->payment_id)->toBe($payment->id)
        ->and($reversal->settlement_account_id)->toBe($accountId)
        ->and($reversal->brand_id)->toBe($order->brand_id)
        ->and((string) $reversal->amount)->toBe((string) $order->total_gross)
        // The refund's own consequences stay exactly where they were: the
        // row carries the reference, it does not re-run the refund.
        ->and($order->refresh()->status)->toBe(OrderStatus::Refunded)
        ->and(CreditNote::withoutBrandScope()->where('order_id', $order->id)->count())->toBe(1);

    Event::assertDispatchedTimes(OrderRefunded::class, 1);
});

it('lets the provider notification own the paperwork when it wins the race (#247)', function (): void {
    // `charge.refunded` for our own refund can be delivered between the API
    // call returning `re_…` and this transaction committing (review finding
    // 5, NEW-3). The provider's path issues its own credit note and
    // `OrderRefunded`, so the unique key on the reversal row decides who
    // does that work: the winner does it, the loser stops. Two credit notes
    // for one refund is paperwork nobody can undo (ADR-0031).
    $this->withBrand();

    $order = refundTestPaidOrder();

    $payment = Payment::factory()->succeeded()->create([
        'order_id' => $order->id,
        'provider' => 'spy',
        'amount' => $order->total_gross,
        'currency' => $order->currency,
    ]);

    app(SettlementAdapterRegistry::class)->register('spy', RefundTestSpyAdapter::class);
    app()->instance(RefundTestSpyAdapter::class, app(RefundTestSpyAdapter::class));

    // The notification lands the moment `RecordOwnRefund` looks for its row
    // — the whole webhook path, not just the row, because that is what a
    // real one does.
    $raced = false;

    DB::listen(function (QueryExecuted $query) use (&$raced, $payment): void {
        if ($raced || ! str_starts_with($query->sql, 'select') || ! str_contains($query->sql, 'payment_reversals')) {
            return;
        }

        $raced = true;

        app(RecordPaymentReversal::class)->handle(
            payment: $payment->fresh(),
            kind: ReversalKind::ExternalRefund,
            status: ReversalStatus::Final,
            amount: (string) $payment->amount,
            currency: $payment->currency,
            providerReference: 're_spy_1',
            reasonCode: null,
            feeAmount: null,
            occurredAt: now(),
        );
    });

    Event::fake([OrderRefunded::class, CreditNoteIssued::class]);

    app(RefundOrder::class)->handle($order, reasonCode: 'customer-request');

    expect($raced)->toBeTrue()
        // One row, one credit note, one announcement — the provider's.
        ->and(PaymentReversal::withoutBrandScope()->where('order_id', $order->id)->count())->toBe(1)
        ->and(PaymentReversal::withoutBrandScope()->sole()->kind)->toBe(ReversalKind::ExternalRefund)
        ->and(CreditNote::withoutBrandScope()->where('order_id', $order->id)->count())->toBe(1)
        ->and($order->refresh()->status)->toBe(OrderStatus::Refunded);

    Event::assertDispatchedTimes(OrderRefunded::class, 1);
});

it('keeps the paperwork to itself when the console wins the race (#247)', function (): void {
    // The other ordering: our row is committed first, so the provider's
    // notification for the same `re_…` converges on it and does nothing —
    // no second credit note, no second `OrderRefunded`.
    $this->withBrand();

    $order = refundTestPaidOrder();

    $payment = Payment::factory()->succeeded()->create([
        'order_id' => $order->id,
        'provider' => 'spy',
        'amount' => $order->total_gross,
        'currency' => $order->currency,
    ]);

    app(SettlementAdapterRegistry::class)->register('spy', RefundTestSpyAdapter::class);
    app()->instance(RefundTestSpyAdapter::class, app(RefundTestSpyAdapter::class));

    Event::fake([OrderRefunded::class, CreditNoteIssued::class]);

    app(RefundOrder::class)->handle($order, reasonCode: 'customer-request');

    expect(PaymentReversal::withoutBrandScope()->sole()->kind)->toBe(ReversalKind::Refund);

    app(RecordPaymentReversal::class)->handle(
        payment: $payment->fresh(),
        kind: ReversalKind::ExternalRefund,
        status: ReversalStatus::Final,
        amount: (string) $payment->amount,
        currency: $payment->currency,
        providerReference: 're_spy_1',
        reasonCode: null,
        feeAmount: null,
        occurredAt: now(),
    );

    expect(PaymentReversal::withoutBrandScope()->where('order_id', $order->id)->count())->toBe(1)
        ->and(PaymentReversal::withoutBrandScope()->sole()->kind)->toBe(ReversalKind::Refund)
        ->and(CreditNote::withoutBrandScope()->where('order_id', $order->id)->count())->toBe(1);

    Event::assertDispatchedTimes(OrderRefunded::class, 1);
});

it('converges the provider notification too when it loses the unique key (#247)', function (): void {
    // The other connection's view of the same race (fix round 3, review
    // F-6). `RecordPaymentReversal`'s lookup misses the console's row —
    // another transaction has written it and not committed — so its own
    // insert raises the unique violation. Catching it and converging is what
    // keeps this a convergence instead of a 500 the provider retries into,
    // and it must not produce a second credit note for the refund the
    // console already documented.
    $this->withBrand();

    $order = refundTestPaidOrder();

    $payment = Payment::factory()->succeeded()->create([
        'order_id' => $order->id,
        'provider' => 'spy',
        'amount' => $order->total_gross,
        'currency' => $order->currency,
    ]);

    // The console's row lands *after* the notification has looked and found
    // nothing, which is the only way to reach the insert's own collision.
    $raced = false;

    DB::listen(function (QueryExecuted $query) use (&$raced, $order, $payment): void {
        if ($raced || ! str_starts_with($query->sql, 'select') || ! str_contains($query->sql, 'payment_reversals')) {
            return;
        }

        $raced = true;

        DB::table('payment_reversals')->insert([
            'id' => (string) Str::ulid(),
            'brand_id' => $order->brand_id,
            'payment_id' => $payment->id,
            'order_id' => $order->id,
            'provider' => 'spy',
            'kind' => ReversalKind::Refund->value,
            'status' => ReversalStatus::Final->value,
            'amount' => $order->total_gross,
            'currency' => $order->currency,
            'provider_reference' => 're_spy_1',
            'occurred_at' => now(),
            'finalized_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    });

    Event::fake([OrderRefunded::class, CreditNoteIssued::class]);

    // No throw: the notification finds the row it collided with and treats it
    // as the replay it is.
    app(RecordPaymentReversal::class)->handle(
        payment: $payment,
        kind: ReversalKind::ExternalRefund,
        status: ReversalStatus::Final,
        amount: (string) $payment->amount,
        currency: $payment->currency,
        providerReference: 're_spy_1',
        reasonCode: null,
        feeAmount: null,
        occurredAt: now(),
    );

    expect($raced)->toBeTrue()
        ->and(PaymentReversal::withoutBrandScope()->where('order_id', $order->id)->count())->toBe(1)
        // The console's row survives, and with it the `re_…` the audit entry
        // reads.
        ->and(PaymentReversal::withoutBrandScope()->sole()->kind)->toBe(ReversalKind::Refund)
        // And the notification writes no paperwork of its own for a refund
        // that is already recorded.
        ->and(CreditNote::withoutBrandScope()->where('order_id', $order->id)->count())->toBe(0);

    Event::assertNotDispatched(OrderRefunded::class);
});

it('issues one credit note when two console refunds race with no provider reference (blocker 1)', function (): void {
    // A `manual` refund has no reference to claim, so the reversal row's
    // unique key cannot serialize anything: two POSTs — a double submit, a
    // browser resubmit — both read `paid` and both issue a credit note.
    // Credit notes are immutable (ADR-0031/0048) and the bookkeeping export
    // books them (ADR-0040), so a duplicate reaches the books with no
    // recovery path. The order row is the claim that closes it.
    $this->withBrand();

    $order = refundTestPaidOrder();

    // The competing refund completes while this one is between its own
    // reads — which is what the second connection's transaction does once it
    // has waited for the order lock.
    $raced = false;

    // Fired on the first read of `payments`, which is before the order lock
    // — the point a second connection's transaction is released from that
    // lock and finishes ahead of this one.
    DB::listen(function (QueryExecuted $query) use (&$raced, $order): void {
        if ($raced || ! str_contains($query->sql, 'from "payments"')) {
            return;
        }

        $raced = true;

        app(RecordRefund::class)->handle($order->fresh(), reasonCode: 'customer-request');
    });

    Event::fake([OrderRefunded::class, CreditNoteIssued::class]);

    app(RefundOrder::class)->handle($order, reasonCode: 'customer-request');

    expect($raced)->toBeTrue()
        ->and(CreditNote::withoutBrandScope()->where('order_id', $order->id)->count())->toBe(1)
        ->and($order->refresh()->status)->toBe(OrderStatus::Refunded);

    // And exactly one announcement: two would have Registration cancel the
    // same seat twice (ADR-0057 point 5).
    Event::assertDispatchedTimes(OrderRefunded::class, 1);
});

it('issues one credit note when the webhook keys our own refund on the charge (blocker 1)', function (): void {
    // The one case the reversal row's unique key cannot converge:
    // `charge.refunds` unexpanded *and* Stripe unreachable, so the webhook
    // keys `ch_…` where `RefundOrder` keyed `re_…`. Different keys, both
    // rows insert — and only the order lock stops the second credit note.
    $this->withBrand();

    $order = refundTestPaidOrder();

    $payment = Payment::factory()->succeeded()->create([
        'order_id' => $order->id,
        'provider' => 'spy',
        'amount' => $order->total_gross,
        'currency' => $order->currency,
    ]);

    app(SettlementAdapterRegistry::class)->register('spy', RefundTestSpyAdapter::class);
    app()->instance(RefundTestSpyAdapter::class, app(RefundTestSpyAdapter::class));

    $raced = false;

    // Fired before this refund takes the order lock, which is where the
    // provider's notification wins the race.
    DB::listen(function (QueryExecuted $query) use (&$raced, $payment): void {
        if ($raced || ! str_contains($query->sql, 'from "payments"')) {
            return;
        }

        $raced = true;

        // The provider's notification, keyed on the charge instead of the
        // refund — its own credit note and `OrderRefunded` included.
        app(RecordPaymentReversal::class)->handle(
            payment: $payment->fresh(),
            kind: ReversalKind::ExternalRefund,
            status: ReversalStatus::Final,
            amount: (string) $payment->amount,
            currency: $payment->currency,
            providerReference: 'ch_spy_1',
            reasonCode: null,
            feeAmount: null,
            occurredAt: now(),
        );
    });

    Event::fake([OrderRefunded::class, CreditNoteIssued::class]);

    app(RefundOrder::class)->handle($order, reasonCode: 'customer-request');

    expect($raced)->toBeTrue()
        ->and(CreditNote::withoutBrandScope()->where('order_id', $order->id)->count())->toBe(1)
        ->and($order->refresh()->status)->toBe(OrderStatus::Refunded);

    Event::assertDispatchedTimes(OrderRefunded::class, 1);
});

it('refuses a second unapplied refund that reaches the payment while the first is in flight (#6)', function (): void {
    // "Refused by construction" was a sequential argument (finding 6): two
    // POSTs both read `succeeded` and both called the provider — Stripe
    // rejects a second full refund, a partial one and both other providers
    // go through twice. The payment's own lock is what makes the claim true.
    $this->withBrand();

    $order = Order::factory()->create(['status' => OrderStatus::Cancelled, 'total_gross' => '119.00']);

    $payment = Payment::factory()->succeeded()->create([
        'order_id' => $order->id,
        'provider' => 'spy',
        'amount' => '119.00',
        'currency' => $order->currency,
    ]);

    app(SettlementAdapterRegistry::class)->register('spy', RefundTestSpyAdapter::class);
    $spy = app(RefundTestSpyAdapter::class);
    app()->instance(RefundTestSpyAdapter::class, $spy);

    app(RefundOrder::class)->handle($order, reasonCode: 'customer-request');

    expect($payment->refresh()->status)->toBe(PaymentStatus::Reversed)
        ->and($spy->refunds)->toHaveCount(1);

    // The second press finds no unapplied money and never reaches the
    // provider.
    expect(fn () => app(RefundOrder::class)->handle($order->refresh(), reasonCode: 'customer-request'))
        ->toThrow(RuntimeException::class);

    expect($spy->refunds)->toHaveCount(1)
        ->and(PaymentReversal::withoutBrandScope()->where('order_id', $order->id)->count())->toBe(1);
});

it('takes the order lock before the payment lock on both money paths (#247, review NEW-5)', function (): void {
    // The console refund locks the order and then writes the reversal row;
    // the provider's notification used to lock the *payment* first and reach
    // the order last. Opposite orders on the same three rows is a deadlock
    // cycle: Postgres kills one of them with SQLSTATE 40P01, which is not a
    // unique violation, so the convergence catch does not see it and the
    // operator gets a 500 for a refund whose money did leave. One order for
    // every path — order, then payment — is the whole fix, and it is what
    // this pins.
    $this->withBrand();

    $order = refundTestPaidOrder();

    $payment = Payment::factory()->succeeded()->create([
        'order_id' => $order->id,
        'provider' => 'spy',
        'amount' => $order->total_gross,
        'currency' => $order->currency,
    ]);

    app(SettlementAdapterRegistry::class)->register('spy', RefundTestSpyAdapter::class);
    app()->instance(RefundTestSpyAdapter::class, app(RefundTestSpyAdapter::class));

    /** @var list<string> $locks */
    $locks = [];

    DB::listen(function (QueryExecuted $query) use (&$locks): void {
        if (! str_contains($query->sql, 'for update')) {
            return;
        }

        foreach (['orders', 'payments', 'payment_reversals'] as $table) {
            if (str_contains($query->sql, sprintf('from "%s"', $table))) {
                $locks[] = $table;

                return;
            }
        }
    });

    Event::fake([OrderRefunded::class, CreditNoteIssued::class]);

    app(RefundOrder::class)->handle($order, reasonCode: 'customer-request');

    // Re-locking a row this transaction already holds is free and happens
    // (`RecordOwnRefund` says the sequence again rather than trusting its
    // caller), so what matters is the order the tables are *first* reached in.
    expect(array_slice(array_values(array_unique($locks)), 0, 2))->toBe(['orders', 'payments']);

    $locks = [];

    app(RecordPaymentReversal::class)->handle(
        payment: $payment->fresh(),
        kind: ReversalKind::Return,
        status: ReversalStatus::Final,
        amount: (string) $payment->amount,
        currency: $payment->currency,
        providerReference: 'chb_lock_order_1',
        reasonCode: null,
        feeAmount: null,
        occurredAt: now(),
    );

    // The same two, in the same order, from the other side.
    expect(array_slice(array_values(array_unique($locks)), 0, 2))->toBe(['orders', 'payments']);
});

it('records no reversal row for a refund no provider gave a reference to (#247)', function (): void {
    // `manual` wires the money back outside the platform: there is no
    // reference, and a row whose `provider_reference` is invented would
    // collide with the unique key the reversal table is built on.
    $this->withBrand();

    $order = refundTestPaidOrder();

    Payment::factory()->succeeded()->create([
        'order_id' => $order->id,
        'provider' => 'spy',
        'amount' => $order->total_gross,
        'currency' => $order->currency,
    ]);

    app(SettlementAdapterRegistry::class)->register('spy', RefundTestSpyAdapter::class);
    $spy = app(RefundTestSpyAdapter::class);
    $spy->reference = null;
    app()->instance(RefundTestSpyAdapter::class, $spy);

    Event::fake([OrderRefunded::class, CreditNoteIssued::class]);

    app(RefundOrder::class)->handle($order, reasonCode: 'customer-request');

    expect(PaymentReversal::withoutBrandScope()->where('order_id', $order->id)->count())->toBe(0)
        ->and($order->refresh()->status)->toBe(OrderStatus::Refunded);
});

it('aborts the refund entirely when the provider refuses it', function (): void {
    $this->withBrand();

    $order = refundTestPaidOrder();

    Payment::factory()->succeeded()->create([
        'order_id' => $order->id,
        'provider' => 'spy',
        'settlement_account_id' => (string) Str::ulid(),
    ]);

    app(SettlementAdapterRegistry::class)->register('spy', RefundTestSpyAdapter::class);
    $spy = app(RefundTestSpyAdapter::class);
    $spy->refuse = true;
    app()->instance(RefundTestSpyAdapter::class, $spy);

    Event::fake([OrderRefunded::class]);

    expect(fn () => app(RefundOrder::class)->handle($order, reasonCode: 'customer-request'))
        ->toThrow(RuntimeException::class, 'provider refused');

    // Never reached: the provider call comes before any domain write, so no
    // record can claim money moved that did not (#236 kept this order).
    expect($order->refresh()->status)->toBe(OrderStatus::Paid)
        ->and(CreditNote::query()->where('order_id', $order->id)->count())->toBe(0);

    Event::assertNotDispatched(OrderRefunded::class);
});

/**
 * #236: the money left Stripe, then the `order_refunded` consumer threw
 * inside the console request — and because the provider call and the domain
 * record shared one transaction with the consumers, the rollback undid the
 * credit note and the `refunded` status while the refund stood at the
 * provider. The order was left `paid` with the money gone.
 *
 * The recorded refund is committed first and the domain events go out after
 * the commit: a failing consumer is still an error the operator sees, but it
 * can no longer erase what the provider already did.
 */
it('keeps the recorded refund when a consumer of OrderRefunded throws (#236)', function (): void {
    $this->withBrand();

    $order = refundTestPaidOrder();

    Event::listen(OrderRefunded::class, function (): void {
        throw new RuntimeException('the consumer blew up');
    });

    expect(fn () => app(RefundOrder::class)->handle($order, reasonCode: 'customer-request'))
        ->toThrow(RuntimeException::class, 'the consumer blew up');

    expect($order->refresh()->status)->toBe(OrderStatus::Refunded)
        ->and(CreditNote::query()->where('order_id', $order->id)->count())->toBe(1);
});

/**
 * #236 fix round 1, review finding 3: a plain `foreach` over the events
 * stopped at the first throwing consumer, so on a three-line cart order
 * lines 2 and 3 never heard about the refund at all — they stayed confirmed
 * with their quota consumed, and nothing rolled back or retried them. Each
 * line is dispatched on its own now; the failures are collected and rethrown
 * as one, so the job fails (and Horizon retries it) without any line being
 * skipped silently.
 */
it('dispatches every line even when the first consumer throws (#236)', function (): void {
    $this->withBrand();

    $order = refundTestPaidCartOrder();

    $first = (string) Str::ulid();
    $second = (string) Str::ulid();

    OrderLine::factory()->create([
        'order_id' => $order->id,
        'position' => 1,
        'settles_kind' => 'registration',
        'settles_ref' => $first,
    ]);
    OrderLine::factory()->create([
        'order_id' => $order->id,
        'position' => 2,
        'settles_kind' => 'registration',
        'settles_ref' => $second,
    ]);

    $seen = [];

    Event::listen(OrderLineCancelled::class, function (OrderLineCancelled $event) use (&$seen, $first): void {
        $seen[] = $event->refId;

        if ($event->refId === $first) {
            throw new RuntimeException('the first line\'s consumer blew up');
        }
    });

    expect(fn () => app(RefundOrder::class)->handle($order, reasonCode: 'customer-request'))
        ->toThrow(RefundEventRelayFailedException::class);

    // Both lines heard about it, in position order, and the refund stands.
    expect($seen)->toBe([$first, $second])
        ->and($order->refresh()->status)->toBe(OrderStatus::Refunded);
});

it('keeps the recorded refund when a consumer of OrderLineCancelled throws (#236)', function (): void {
    // The cart order's per-line leg, which is the one that actually failed
    // on dev: `CancelRegistrationOnOrderLineCancelled` runs on this event.
    $this->withBrand();

    $order = refundTestPaidCartOrder();

    OrderLine::factory()->create([
        'order_id' => $order->id,
        'settles_kind' => 'registration',
        'settles_ref' => (string) Str::ulid(),
    ]);

    Event::listen(OrderLineCancelled::class, function (): void {
        throw new RuntimeException('the line consumer blew up');
    });

    expect(fn () => app(RefundOrder::class)->handle($order, reasonCode: 'customer-request'))
        ->toThrow(RuntimeException::class, 'the line consumer blew up');

    expect($order->refresh()->status)->toBe(OrderStatus::Refunded)
        ->and(CreditNote::query()->where('order_id', $order->id)->count())->toBe(1);
});

/**
 * A brand with an operator attached — `IssueInvoiceOnOrderPaid`'s document
 * pipeline needs a seller to render (`OperatorSettings::sellerForBrand()`).
 * Duplicated from `PaidInvoiceTest::paidInvoiceBrand()` rather than reused:
 * Pest helper functions only exist once their defining file has been loaded
 * by the current run, so a file that must pass in isolation (`php artisan
 * test app-modules/commerce/tests/RefundAndCreditNoteTest.php` alone) cannot
 * call a helper defined in a sibling test file (.ai/rules/tests.md).
 */
function refundTestBrandWithOperator(): Brand
{
    $brand = test()->withBrand();
    $brand->operator()->associate(Operator::factory()->create())->save();

    return $brand;
}

function refundTestBillingParty(?string $name = 'Erika Musterfrau', ?string $email = 'erika@musterfrau.example'): BillingPartyData
{
    return BillingPartyData::ofAccount((string) $name, (string) $email);
}

/**
 * A pending cart order with one line and a billing party (Task 2's
 * `OrderPaid` → paid Rechnung shape) — ready for `RecordPaymentSuccess` to
 * settle and, from there, for `RefundOrder` to reverse.
 */
function refundTestCartOrder(?BillingPartyData $billingParty = null): Order
{
    $order = Order::factory()->create([
        'settles_type' => 'cart',
        'total_net' => '100.00',
        'total_tax' => '19.00',
        'total_gross' => '119.00',
        'billing_party' => $billingParty ?? refundTestBillingParty(),
    ]);

    OrderLine::factory()->forOrder($order)->create([
        'label' => 'Rush Shirt',
        'unit_amount_net' => '100.00',
        'tax_rate' => '19.00',
        'line_net' => '100.00',
        'line_tax' => '19.00',
        'line_gross' => '119.00',
    ]);

    return $order;
}

function refundTestCardPayment(Order $order, string $provider = 'stripe'): Payment
{
    return Payment::factory()->create([
        'order_id' => $order->id,
        'provider' => $provider,
        'amount' => $order->total_gross,
        'currency' => $order->currency,
    ]);
}

/**
 * End to end (Task 3, ADR-0048 point 2): a card-paid cart order (Task 2's
 * `OrderPaid` → paid Rechnung setup) refunded through `RefundOrder` leaves a
 * Gutschrift the payer can both read about and download — not just a
 * `CreditNote` row.
 *
 * `GenerateCreditNoteDocument` is never dispatched by hand here: it runs
 * `afterCommit()` from inside `IssueCreditNote`'s own transaction, which
 * under RefreshDatabase never reaches transaction level 0 — so the dispatch
 * runs synchronously, in-process, by the time `RefundOrder::handle()`
 * returns (`.ai/rules/tests.md`). `Storage::fake('invoices')` here is a
 * second, harmless fake of the disk `TestCase::setUp()` already faked
 * globally — kept explicit so this test's intent reads on its own.
 */
it('refunds a paid cart order into a mailed, downloadable Gutschrift', function (): void {
    $brand = refundTestBrandWithOperator();
    Storage::fake('invoices');
    Mail::fake();

    $payer = User::factory()->create();
    $order = refundTestCartOrder(refundTestBillingParty($payer->name, $payer->email));
    $order->forceFill(['payer_user_id' => $payer->getKey()])->save();

    // manual: RefundOrder's PSP call resolves ManualSettlementAdapter, a
    // true no-op (ADR-0039) — no live provider call in a test.
    $payment = refundTestCardPayment($order, 'manual');
    app(RecordPaymentSuccess::class)->handle($payment);

    $invoice = Invoice::query()->where('order_id', $order->id)->sole();
    expect($invoice->paid_at)->not->toBeNull();

    app(RefundOrder::class)->handle($order->fresh(), reasonCode: 'customer-request');

    $note = CreditNote::query()->where('order_id', $order->id)->sole();

    expect($note->document_path)->not->toBeNull()
        ->and($note->document_disk)->toBe('invoices');

    Storage::disk('invoices')->assertExists($note->document_path);

    // Two TemplatedMails go out here — the paid Rechnung (Task 2) and the
    // Gutschrift (this task) — so the matcher must pick its own out by
    // subject before asserting on it, not assert unconditionally: a failed
    // `expect()` inside `assertSent`'s callback throws rather than counting
    // as "no match, try the next mail".
    Mail::assertSent(TemplatedMail::class, function (TemplatedMail $mail) use ($note): bool {
        if (! str_contains($mail->envelope()->subject, $note->credit_note_number)) {
            return false;
        }

        $attachments = $mail->attachments();

        expect($attachments)->toHaveCount(1)
            ->and($attachments[0]->as)->toBe($note->credit_note_number.'.pdf');

        return true;
    });

    test()->actingAs($payer)
        ->get('/my/orders')
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('commerce/my/Index')
            ->where('orders.0.creditNotes.0.number', $note->credit_note_number)
            ->where(
                'orders.0.creditNotes.0.documentUrl',
                fn (?string $url): bool => $url !== null && str_contains($url, 'signature='),
            ));
});

/**
 * Whole-branch review finding 3. `RecordRefund` emitted `OrderRefunded` and
 * nothing else, and `CancelRegistrationOnOrderUndone` ignores a `cart`
 * order — so refunding a cart order that paid for a registration sent the
 * money back and left the place, the quota and the admission standing,
 * while the same button on a direct registration order released them. The
 * per-line event `CancelOrderForUnpaidReversal` already emits for exactly
 * this reason is now emitted here too.
 */
function refundTestPaidCartOrder(): Order
{
    $order = Order::factory()->paid()->create([
        'settles_type' => 'cart',
        'settles_id' => (string) Str::ulid(),
    ]);

    Invoice::factory()->forOrder($order)->create();

    return $order;
}

it('releases a cart order\'s registration lines on a whole refund (#73)', function (): void {
    $this->withBrand();

    $order = refundTestPaidCartOrder();

    $registrationLine = OrderLine::factory()->create([
        'order_id' => $order->id,
        'position' => 1,
        'settles_kind' => 'registration',
        'settles_ref' => (string) Str::ulid(),
    ]);
    $productLine = OrderLine::factory()->create([
        'order_id' => $order->id,
        'position' => 2,
        'settles_kind' => 'product',
        'settles_ref' => (string) Str::ulid(),
    ]);

    Event::fake([OrderRefunded::class, OrderLineCancelled::class]);

    app(RefundOrder::class)->handle($order, reasonCode: 'customer-request');

    // One event, for the registration line only: a product line is
    // Commerce's own business.
    Event::assertDispatchedTimes(OrderLineCancelled::class, 1);
    Event::assertDispatched(
        OrderLineCancelled::class,
        fn (OrderLineCancelled $e): bool => $e->orderId === $order->id
            && $e->kind === 'registration'
            && $e->refId === $registrationLine->settles_ref
            && $e->reasonCode === RecordRefund::REASON_ORDER_REFUNDED
            && $e->cancelledByUserId === null,
    );

    expect($productLine->settles_ref)->not->toBe($registrationLine->settles_ref);
});

it('releases nothing on a partial refund of a cart order (#73)', function (): void {
    $this->withBrand();

    $order = refundTestPaidCartOrder();

    OrderLine::factory()->create([
        'order_id' => $order->id,
        'settles_kind' => 'registration',
        'settles_ref' => (string) Str::ulid(),
    ]);

    Event::fake([OrderLineCancelled::class]);

    app(RefundOrder::class)->handle(
        $order,
        reasonCode: 'goodwill',
        amount: bcdiv((string) $order->total_gross, '2', 2),
    );

    Event::assertNotDispatched(OrderLineCancelled::class);
});

it('emits no line event for a direct registration order, which OrderRefunded already names (#73)', function (): void {
    $this->withBrand();

    $order = refundTestPaidOrder();

    OrderLine::factory()->create([
        'order_id' => $order->id,
        'settles_kind' => 'registration',
        'settles_ref' => $order->settles_id,
    ]);

    Event::fake([OrderLineCancelled::class]);

    app(RefundOrder::class)->handle($order, reasonCode: 'customer-request');

    Event::assertNotDispatched(OrderLineCancelled::class);
});

/**
 * D-REG-15 / #7: the per-unit refund a reduced registration triggers.
 *
 * A paid, invoiced order of three units at 19.00 net each, its line carrying
 * the `settles_kind`/`settles_ref` pair both order shapes write. The spy
 * adapter is registered so the provider leg is observable without an SDK.
 *
 * @return array{0: Order,1: OrderLine, 2: RefundTestSpyAdapter}
 */
function refundTestUnitOrder(string $registrationId, int $quantity = 3): array
{
    $account = SettlementAccount::factory()->create(['provider' => 'spy']);

    $order = Order::factory()->paid()->create([
        'settles_type' => 'registration',
        'settles_id' => $registrationId,
        'request_key' => $registrationId,
        'currency' => 'EUR',
        'total_net' => bcmul('19.00', (string) $quantity, 2),
        'total_tax' => bcmul('3.61', (string) $quantity, 2),
        'total_gross' => bcmul('22.61', (string) $quantity, 2),
    ]);

    $line = OrderLine::factory()->forOrder($order)->create([
        'settles_kind' => 'registration',
        'settles_ref' => $registrationId,
        'quantity' => $quantity,
        'unit_amount_net' => '19.00',
        'tax_rate' => '19.00',
        'line_net' => bcmul('19.00', (string) $quantity, 2),
        'line_tax' => bcmul('3.61', (string) $quantity, 2),
        'line_gross' => bcmul('22.61', (string) $quantity, 2),
    ]);

    Invoice::factory()->create([
        'order_id' => $order->id,
        'total_net' => $order->total_net,
        'total_tax' => $order->total_tax,
        'total_gross' => $order->total_gross,
    ]);

    Payment::factory()->create([
        'order_id' => $order->id,
        'settlement_account_id' => $account->id,
        'provider' => 'spy',
        'status' => PaymentStatus::Succeeded,
        'amount' => $order->total_gross,
        'currency' => 'EUR',
        'received_at' => now(),
    ]);

    app(SettlementAdapterRegistry::class)->register('spy', RefundTestSpyAdapter::class);
    $spy = app(RefundTestSpyAdapter::class);
    app()->instance(RefundTestSpyAdapter::class, $spy);

    return [$order, $line, $spy];
}

function refundTestReductionEvent(
    string $registrationId,
    string $brandId,
    string $reductionId,
    int $units,
): RegistrationQuantityReduced {
    return new RegistrationQuantityReduced(
        registrationId: $registrationId,
        reductionId: $reductionId,
        registrationTypeId: (string) Str::ulid(),
        eventId: (string) Str::ulid(),
        brandId: $brandId,
        registrantUserId: (string) Str::ulid(),
        removedUnits: $units,
        previousQuantity: 3,
        newQuantity: 3 - $units,
        removedGrants: [[
            'kind' => 'admission',
            'role' => 'visitor',
            'quantity' => $units,
            'resource_type_id' => null,
            'resource_type_ids' => null,
            'seating_mode' => null,
        ]],
        reasonCode: 'registrant-request',
        reducedByUserId: null,
        reducedAt: now()->toIso8601String(),
    );
}

it('refunds one unit at the line rate, leaves the order paid and credits net plus tax', function (): void {
    $brand = $this->withBrand();
    $registrationId = (string) Str::ulid();

    [$order, , $spy] = refundTestUnitOrder($registrationId);

    Event::fake([OrderRefunded::class, OrderLineCancelled::class, CreditNoteIssued::class]);

    app(RefundUnitsOnRegistrationQuantityReduced::class)->handle(
        refundTestReductionEvent($registrationId, $brand->getKey(), (string) Str::ulid(), 1),
    );

    // 19.00 net at 19% is 3.61 tax and 22.61 gross — one unit, not the order.
    expect($spy->refunds)->toHaveCount(1)
        ->and($spy->refunds[0]['amount'])->toBe('22.61')
        // The sale still stands for the two units the buyer kept.
        ->and($order->refresh()->status)->toBe(OrderStatus::Paid);

    $creditNote = CreditNote::withoutBrandScope()->where('order_id', $order->id)->sole();

    expect((string) $creditNote->total_net)->toBe('19.00')
        ->and((string) $creditNote->total_tax)->toBe('3.61')
        ->and((string) $creditNote->total_gross)->toBe('22.61')
        // #7's first criterion: net + tax == gross on the row the document
        // renders from, so the rate it derives is the line's own 19%.
        ->and(bcadd((string) $creditNote->total_net, (string) $creditNote->total_tax, 2))
        ->toBe((string) $creditNote->total_gross);

    Event::assertDispatched(
        OrderRefunded::class,
        fn (OrderRefunded $e): bool => $e->orderId === $order->id && $e->whole === false,
    );
    // Nothing was cancelled — the units are Registration's business and it
    // has already done it.
    Event::assertNotDispatched(OrderLineCancelled::class);
});

it('allows a second reduction of the same order and refuses a replay of the first', function (): void {
    $brand = $this->withBrand();
    $registrationId = (string) Str::ulid();

    [$order, , $spy] = refundTestUnitOrder($registrationId);

    $firstReduction = (string) Str::ulid();

    app(RefundUnitsOnRegistrationQuantityReduced::class)->handle(
        refundTestReductionEvent($registrationId, $brand->getKey(), $firstReduction, 1),
    );

    // The same announcement again: the credit note's `reduction_key` is the
    // claim, so the provider is never asked twice.
    app(RefundUnitsOnRegistrationQuantityReduced::class)->handle(
        refundTestReductionEvent($registrationId, $brand->getKey(), $firstReduction, 1),
    );

    expect($spy->refunds)->toHaveCount(1)
        ->and(CreditNote::withoutBrandScope()->where('order_id', $order->id)->count())->toBe(1);

    // A genuinely second reduction goes through: the order is still `paid`,
    // which is exactly what the partial branch preserves.
    $spy->reference = 're_spy_2';

    app(RefundUnitsOnRegistrationQuantityReduced::class)->handle(
        refundTestReductionEvent($registrationId, $brand->getKey(), (string) Str::ulid(), 1),
    );

    expect($spy->refunds)->toHaveCount(2)
        ->and(CreditNote::withoutBrandScope()->where('order_id', $order->id)->count())->toBe(2)
        ->and($order->refresh()->status)->toBe(OrderStatus::Paid);
});

it('never credits more than the invoice is worth', function (): void {
    $this->withBrand();

    $order = refundTestPaidOrder();
    $invoice = Invoice::withoutBrandScope()->where('order_id', $order->id)->sole();

    app(IssueCreditNote::class)->handle(
        order: $order,
        reasonCode: IssueCreditNote::REASON_REFUND,
        totalGross: (string) $invoice->total_gross,
    );

    // #7's third criterion: the sum of an invoice's credit notes never
    // exceeds its gross, and a credit note cannot be corrected afterwards.
    expect(fn () => app(IssueCreditNote::class)->handle(
        order: $order,
        reasonCode: IssueCreditNote::REASON_REFUND,
        totalGross: '0.01',
    ))->toThrow(CreditNoteExceedsInvoiceException::class);
});

it('refunds the unused units of a partly checked-in registration, but not more', function (): void {
    // ADR-0037's own consequence: the per-admission keying exists for this.
    $brand = $this->withBrand();
    $registrationId = (string) Str::ulid();

    [$order, , $spy] = refundTestUnitOrder($registrationId);

    // One of the three was used.
    event(refundTestCheckIn($order));

    app(RefundUnitsOnRegistrationQuantityReduced::class)->handle(
        refundTestReductionEvent($registrationId, $brand->getKey(), (string) Str::ulid(), 2),
    );

    expect($spy->refunds)->toHaveCount(1)
        ->and($spy->refunds[0]['amount'])->toBe('45.22');

    // A third unit would be the one that walked through the door.
    app(RefundUnitsOnRegistrationQuantityReduced::class)->handle(
        refundTestReductionEvent($registrationId, $brand->getKey(), (string) Str::ulid(), 3),
    );

    expect($spy->refunds)->toHaveCount(1);
});

it('does nothing for a registration that never produced an order', function (): void {
    $brand = $this->withBrand();

    Event::fake([OrderRefunded::class, CreditNoteIssued::class]);

    // A `free` or `external` registration never caused a Commerce record
    // (ADR-0026) — the reduction still happened, there is just no money.
    app(RefundUnitsOnRegistrationQuantityReduced::class)->handle(
        refundTestReductionEvent((string) Str::ulid(), $brand->getKey(), (string) Str::ulid(), 1),
    );

    Event::assertNotDispatched(OrderRefunded::class);
    Event::assertNotDispatched(CreditNoteIssued::class);
});

/**
 * #7 / D-0.8.2-1: an invoice that mixes tax rates, which is the case the
 * old shape got wrong.
 *
 * Two lines, 19% and 7%, summing to 300.00 net + 45.00 VAT = 345.00 gross.
 * The invoice's own allocation is therefore a *blend* — 45.00 on 300.00 is
 * 15.00%, a rate nobody charged and nobody may be shown.
 */
function refundTestMixedRateOrder(): array
{
    $order = Order::factory()->paid()->create([
        'total_net' => '300.00',
        'total_tax' => '45.00',
        'total_gross' => '345.00',
    ]);

    $standard = OrderLine::factory()->forOrder($order)->create([
        'position' => 1,
        'label' => 'Konferenzticket',
        'quantity' => 2,
        'unit_amount_net' => '100.00',
        'tax_class' => TaxClass::Standard,
        'tax_rate' => '19.00',
        'line_net' => '200.00',
        'line_tax' => '38.00',
        'line_gross' => '238.00',
    ]);

    $reduced = OrderLine::factory()->forOrder($order)->create([
        'position' => 2,
        'label' => 'Tagungsband',
        'quantity' => 1,
        'unit_amount_net' => '100.00',
        'tax_class' => TaxClass::Reduced,
        'tax_rate' => '7.00',
        'line_net' => '100.00',
        'line_tax' => '7.00',
        'line_gross' => '107.00',
    ]);

    Invoice::factory()->create([
        'order_id' => $order->id,
        'total_net' => $order->total_net,
        'total_tax' => $order->total_tax,
        'total_gross' => $order->total_gross,
    ]);

    return [$order, $standard, $reduced];
}

it('computes a partial credit note per line rather than inheriting the invoice\'s allocation (#7)', function (): void {
    $this->withBrand();

    [$order, , $reduced] = refundTestMixedRateOrder();

    // The reduced-rate line alone: one unit of 100.00 net at 7%.
    $note = app(IssueCreditNote::class)->handle(
        order: $order,
        reasonCode: IssueCreditNote::REASON_REFUND,
        lines: [new CreditNoteLineSelectionData($reduced->getKey(), 1)],
    );

    // This is the whole defect, in three numbers. Inheriting the invoice's
    // allocation for a gross of 107.00 gave net 300.00 and tax 45.00 — the
    // *invoice's* figures beside a smaller total — and deriving a rate from
    // them gave 15.00%. The line's own rate is 7%, and 7.00 is the VAT the
    // buyer was actually charged on what is going back.
    expect((string) $note->total_net)->toBe('100.00')
        ->and((string) $note->total_tax)->toBe('7.00')
        ->and((string) $note->total_gross)->toBe('107.00')
        ->and(bcadd((string) $note->total_net, (string) $note->total_tax, 2))
        ->toBe((string) $note->total_gross);

    /** @var CreditNoteLine $line */
    $line = CreditNoteLine::withoutBrandScope()->where('credit_note_id', $note->getKey())->sole();

    expect($line->order_line_id)->toBe($reduced->getKey())
        ->and($line->quantity)->toBe(1)
        ->and((string) $line->tax_rate)->toBe('7.00')
        ->and($line->label)->toBe('Tagungsband')
        ->and((string) $line->line_tax)->toBe('7.00');

    // The contrast, on the same invoice and for the same 107.00: the old
    // shape — a gross with no lines — still exists for the whole-invoice
    // credit note, and it still copies the invoice's net and tax. That is
    // exact when the whole invoice is credited and wrong for a subset,
    // which is why a subset may no longer be expressed that way.
    $inherited = app(IssueCreditNote::class)->handle(
        order: $order->refresh(),
        reasonCode: IssueCreditNote::REASON_REFUND,
        totalGross: '107.00',
    );

    expect((string) $inherited->total_tax)->toBe('45.00')
        ->and((string) $inherited->total_net)->toBe('300.00')
        // 45.00 on 300.00 is the 15% blend `CreditNoteDocumentRenderer`
        // used to print for this document. Nobody was ever charged 15%.
        ->and((string) $inherited->total_tax)->not->toBe((string) $note->total_tax);
});

it('credits two rates in one partial note, each at its own rate (#7)', function (): void {
    $this->withBrand();

    [$order, $standard, $reduced] = refundTestMixedRateOrder();

    // One unit of each: 100.00 at 19% and 100.00 at 7%.
    $note = app(IssueCreditNote::class)->handle(
        order: $order,
        reasonCode: IssueCreditNote::REASON_REFUND,
        lines: [
            new CreditNoteLineSelectionData($standard->getKey(), 1),
            new CreditNoteLineSelectionData($reduced->getKey(), 1),
        ],
    );

    // 19.00 + 7.00 = 26.00 of VAT on 200.00 net. A blended 15% would have
    // said 30.00, and the pro-rated share of the invoice's own split would
    // have said something else again.
    expect((string) $note->total_net)->toBe('200.00')
        ->and((string) $note->total_tax)->toBe('26.00')
        ->and((string) $note->total_gross)->toBe('226.00');

    $rates = CreditNoteLine::withoutBrandScope()
        ->where('credit_note_id', $note->getKey())
        ->orderBy('position')
        ->pluck('line_tax', 'tax_rate')
        ->map(fn (mixed $tax): string => (string) $tax)
        ->all();

    expect($rates)->toBe(['19.00' => '19.00', '7.00' => '7.00']);
});

it('refuses a second credit note that would over-credit a line the invoice still has room for (#7)', function (): void {
    $this->withBrand();

    [$order, , $reduced] = refundTestMixedRateOrder();

    app(IssueCreditNote::class)->handle(
        order: $order,
        reasonCode: IssueCreditNote::REASON_REFUND,
        lines: [new CreditNoteLineSelectionData($reduced->getKey(), 1)],
    );

    // The invoice has 238.00 of its 345.00 left, so the *total* guard would
    // wave this through — the reduced line itself has nothing left, and
    // that is what `credit_note_lines` is for. Refused, never clamped to
    // zero units: a credit note cannot be corrected afterwards (ADR-0031).
    expect(fn () => app(IssueCreditNote::class)->handle(
        order: $order,
        reasonCode: IssueCreditNote::REASON_REFUND,
        lines: [new CreditNoteLineSelectionData($reduced->getKey(), 1)],
    ))->toThrow(CreditNoteExceedsInvoiceException::class, 'Tagungsband');

    expect(CreditNote::withoutBrandScope()->where('order_id', $order->id)->count())->toBe(1);
});

it('refuses more units of a line than were ever sold, and a line from another order (#7)', function (): void {
    $this->withBrand();

    [$order, $standard] = refundTestMixedRateOrder();

    expect(fn () => app(IssueCreditNote::class)->handle(
        order: $order,
        reasonCode: IssueCreditNote::REASON_REFUND,
        lines: [new CreditNoteLineSelectionData($standard->getKey(), 3)],
    ))->toThrow(CreditNoteExceedsInvoiceException::class);

    [, $strangersLine] = refundTestMixedRateOrder();

    expect(fn () => app(IssueCreditNote::class)->handle(
        order: $order,
        reasonCode: IssueCreditNote::REASON_REFUND,
        lines: [new CreditNoteLineSelectionData($strangersLine->getKey(), 1)],
    ))->toThrow(CreditNoteLineSelectionException::class);

    // A fraction of a unit is not expressible at all, and a zero is not a
    // credit note: both are refused rather than rounded into a document.
    expect(fn () => app(IssueCreditNote::class)->handle(
        order: $order,
        reasonCode: IssueCreditNote::REASON_REFUND,
        lines: [new CreditNoteLineSelectionData($standard->getKey(), 0)],
    ))->toThrow(CreditNoteLineSelectionException::class);

    expect(CreditNote::withoutBrandScope()->where('order_id', $order->id)->count())->toBe(0);
});

it('keeps the order paid for a line-selected partial refund, and releases nothing (#7)', function (): void {
    $this->withBrand();

    [$order, , $reduced] = refundTestMixedRateOrder();

    Event::fake([OrderRefunded::class, OrderLineCancelled::class]);

    app(RefundOrder::class)->handle(
        order: $order,
        reasonCode: 'customer-request',
        creditLines: [new CreditNoteLineSelectionData($reduced->getKey(), 1)],
    );

    // The buyer keeps the two conference tickets, so the sale stands and a
    // second partial of the same order stays possible.
    expect($order->refresh()->status)->toBe(OrderStatus::Paid);

    Event::assertDispatched(
        OrderRefunded::class,
        fn (OrderRefunded $e): bool => $e->orderId === $order->id
            && $e->whole === false
            && $e->amountRefunded === '107.00',
    );
    Event::assertNotDispatched(OrderLineCancelled::class);
});

it('refuses a line selection before the provider is asked for the money (#7)', function (): void {
    $brand = $this->withBrand();

    [$order, , $reduced] = refundTestMixedRateOrder();

    $account = SettlementAccount::factory()->create(['provider' => 'spy']);
    Payment::factory()->create([
        'order_id' => $order->id,
        'settlement_account_id' => $account->id,
        'provider' => 'spy',
        'status' => PaymentStatus::Succeeded,
        'amount' => $order->total_gross,
        'currency' => 'EUR',
        'received_at' => now(),
    ]);

    app(SettlementAdapterRegistry::class)->register('spy', RefundTestSpyAdapter::class);
    $spy = app(RefundTestSpyAdapter::class);
    app()->instance(RefundTestSpyAdapter::class, $spy);

    app(RefundOrder::class)->handle(
        order: $order,
        reasonCode: 'customer-request',
        creditLines: [new CreditNoteLineSelectionData($reduced->getKey(), 1)],
    );

    expect($spy->refunds)->toHaveCount(1)
        // The gross is the selection's own, computed per line — never a
        // number the caller supplied.
        ->and($spy->refunds[0]['amount'])->toBe('107.00');

    // The same selection again: nothing is left of that line, so the money
    // leg must not run at all. #236 keeps the provider first, which is
    // exactly why the guard has to be ahead of it.
    expect(fn () => app(RefundOrder::class)->handle(
        order: $order->refresh(),
        reasonCode: 'customer-request',
        creditLines: [new CreditNoteLineSelectionData($reduced->getKey(), 1)],
    ))->toThrow(CreditNoteExceedsInvoiceException::class);

    expect($spy->refunds)->toHaveCount(1)
        ->and(CreditNote::withoutBrandScope()->where('brand_id', $brand->getKey())->where('order_id', $order->id)->count())
        ->toBe(1);
});

it('records a reduction\'s units on the credited-quantity ledger, so a console partial cannot spend them twice (#7)', function (): void {
    $brand = $this->withBrand();
    $registrationId = (string) Str::ulid();

    [$order, $line] = refundTestUnitOrder($registrationId);

    // Two of the three units go back through D-REG-15's own path.
    app(RefundUnitsOnRegistrationQuantityReduced::class)->handle(
        refundTestReductionEvent($registrationId, $brand->getKey(), (string) Str::ulid(), 2),
    );

    expect(CreditNoteLine::creditedQuantitiesFor($brand->getKey(), [(string) $line->getKey()]))
        ->toBe([(string) $line->getKey() => 2]);

    // The third unit is creditable; a fourth is not, even though nothing at
    // the invoice level says so yet.
    app(IssueCreditNote::class)->handle(
        order: $order->refresh(),
        reasonCode: IssueCreditNote::REASON_REFUND,
        lines: [new CreditNoteLineSelectionData($line->getKey(), 1)],
    );

    expect(fn () => app(IssueCreditNote::class)->handle(
        order: $order->refresh(),
        reasonCode: IssueCreditNote::REASON_REFUND,
        lines: [new CreditNoteLineSelectionData($line->getKey(), 1)],
    ))->toThrow(CreditNoteExceedsInvoiceException::class);
});
