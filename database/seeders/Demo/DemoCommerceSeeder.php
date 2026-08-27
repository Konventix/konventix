<?php

declare(strict_types=1);

namespace Database\Seeders\Demo;

use Illuminate\Support\Collection;
use Konventix\Commerce\Actions\BookManualPayment;
use Konventix\Commerce\Actions\IssueCreditNote;
use Konventix\Commerce\Actions\RaiseDunningLevel;
use Konventix\Commerce\Actions\SaveProduct;
use Konventix\Commerce\Data\ProductVariantData;
use Konventix\Commerce\Data\SaveProductData;
use Konventix\Commerce\Enums\OrderStatus;
use Konventix\Commerce\Enums\PriceDisplay;
use Konventix\Commerce\Enums\TaxClass;
use Konventix\Commerce\Models\BookkeepingSettings;
use Konventix\Commerce\Models\CreditNote;
use Konventix\Commerce\Models\Invoice;
use Konventix\Commerce\Models\Order;
use Konventix\Commerce\Models\Product;
use Konventix\Commerce\Models\SettlementAccount;
use Konventix\Event\Models\Event;
use Konventix\Identity\Models\User;
use Konventix\Platform\Actions\UpdateBrandFeatures;
use Konventix\Platform\Models\Brand;
use Konventix\Platform\Models\Operator;
use Konventix\Registration\Actions\SubmitRegistration;
use Konventix\Registration\Data\SubmitRegistrationData;
use Konventix\Registration\Enums\RegistrationSettlement;
use Konventix\Registration\Models\RegistrationType;

/**
 * The money-moving slice of the demo: a priced, invoice-settled "Supporter"
 * type whose registrations flow through the real settlement chain —
 * SubmitRegistration confirms, RequestSettlement emits, Commerce places the
 * order and issues the invoice (ADR-0006) — then every second invoice gets a
 * manual bank-transfer payment booked, leaving the rest open for the
 * dunning/receivables views.
 *
 * On top of the orders, the surrounding configuration the money screens read:
 * settlement accounts (a connected bank-transfer one and a disconnected PSP,
 * so both states render), the operator's DATEV bookkeeping numbers, one
 * credit note against a paid order, and a dunning pass that raises whatever
 * is genuinely overdue.
 *
 * Idempotent on the Supporter type's existence, mirroring how events are
 * skipped wholesale: re-running must not double-submit registrations.
 */
final class DemoCommerceSeeder
{
    public function __construct(
        private readonly SubmitRegistration $submit,
        private readonly BookManualPayment $bookPayment,
        private readonly IssueCreditNote $issueCreditNote,
        private readonly RaiseDunningLevel $raiseDunning,
        private readonly SaveProduct $saveProduct,
        private readonly UpdateBrandFeatures $updateFeatures,
    ) {}

    /**
     * Bank transfer connected, a PSP left disconnected. The disconnected row
     * is the point: the settlement-accounts screen exists to connect and
     * disconnect, and a screen with only happy rows shows neither.
     *
     * Scoped by the brand in context, like every other brand-owned write here
     * (ADR-0010) — no brand argument to get wrong.
     */
    public function seedSettlementAccounts(): void
    {
        if (SettlementAccount::query()->exists()) {
            return;
        }

        SettlementAccount::factory()->create([
            'label' => 'Bank transfer (Sparkasse)',
        ]);

        SettlementAccount::factory()
            ->provider(SettlementAccount::PROVIDER_STRIPE)
            ->disconnected()
            ->create(['label' => 'Stripe (was connected in 2025)']);
    }

    /**
     * DATEV export numbers, which the bookkeeping screen refuses to export
     * without. Operator-scoped, not brand-scoped: the books belong to the
     * legal entity (ADR-0016).
     */
    public function seedBookkeepingSettings(?Operator $operator): void
    {
        if ($operator === null) {
            return;
        }

        BookkeepingSettings::query()->firstOrCreate(
            ['operator_id' => $operator->getKey()],
            ['consultant_number' => 12345, 'client_number' => 67890],
        );
    }

    /**
     * The shop (ADR-0047, Task 8) — a per-brand feature with no module gate
     * behind it, so it only ever demonstrates as "on for a LAN, off for a
     * trade fair" the same way the gateable modules already do (this seed's
     * one structural difference is deliberately never duplicated elsewhere).
     * A LAN party sells merch to its players; a trade fair's registration
     * catalogue is the whole story for it here.
     *
     * The two products go through the real `SaveProduct` action — variants
     * and all — rather than factory rows, so the console and the public
     * shop both render against what a real save produces. One stays in
     * stock; the other's sole variant is created with zero capacity, which
     * is what `Product::isSoldOut()` actually reads — not a fixture flag.
     *
     * Idempotent on any product already existing for the brand, and a
     * no-op outright for a brand this archetype does not sell merch for.
     */
    public function seedShop(Brand $brand, EventArchetype $archetype, ?Event $event): void
    {
        if ($archetype !== EventArchetype::Lan) {
            return;
        }

        $this->updateFeatures->handle($brand, ['commerce:shop' => true]);

        if (Product::query()->exists()) {
            return;
        }

        $this->saveProduct->handle($brand, null, new SaveProductData(
            name: 'Event T-Shirt',
            slug: 'event-t-shirt',
            description: "Soft cotton tee with this year's line-up on the back.",
            eventId: $event?->getKey(),
            imageMediaId: null,
            taxClass: TaxClass::Standard,
            unitAmountNet: '19.00',
            priceDisplay: PriceDisplay::Gross,
            published: true,
            stockCapacity: null,
            variants: [
                new ProductVariantData(id: null, label: 'S', sku: null, unitAmountNet: null, stockCapacity: 30, position: 0),
                new ProductVariantData(id: null, label: 'M', sku: null, unitAmountNet: null, stockCapacity: 40, position: 1),
                new ProductVariantData(id: null, label: 'L', sku: null, unitAmountNet: null, stockCapacity: 20, position: 2),
            ],
        ));

        // Sold out on purpose: the shop's badge and the disabled variant
        // select both need a real zero-stock row to render against, not a
        // fixture flag. `stockCapacity: 0` on the implicit variant is what
        // does that — SaveProductData carries no `stockConsumed`, since a
        // save can only ever set capacity.
        $this->saveProduct->handle($brand, null, new SaveProductData(
            name: 'Limited Edition Poster',
            slug: 'limited-edition-poster',
            description: 'Numbered print, one per attendee — this run is gone.',
            eventId: $event?->getKey(),
            imageMediaId: null,
            taxClass: TaxClass::Reduced,
            unitAmountNet: '12.00',
            priceDisplay: PriceDisplay::Net,
            published: true,
            stockCapacity: 0,
            variants: [],
        ));
    }

    /**
     * @param  Collection<int, User>  $members
     */
    public function handle(Brand $brand, Event $event, Collection $members, int $registrations): void
    {
        $alreadySeeded = RegistrationType::query()
            ->where('event_id', $event->getKey())
            ->where('name', 'Supporter')
            ->exists();

        if ($alreadySeeded) {
            return;
        }

        $supporter = RegistrationType::factory()
            ->forEvent($event->getKey())
            ->settlement(RegistrationSettlement::Invoice)
            ->priced(unitAmountNet: '49.00')
            ->create(['name' => 'Supporter', 'terms_days' => 14]);

        if ($members->isEmpty() || $registrations === 0) {
            return;
        }

        // Roughly a third of the visitor volume upgrades to a paid ticket.
        $supporterCount = min(max(1, intdiv($registrations, 3)), $members->count());

        $members->random($supporterCount)->values()
            ->each(function (User $user, int $index) use ($supporter): void {
                // Invoice settlement requires the full billing party at
                // submission (a legally valid invoice needs an address).
                $registration = $this->submit->handle($supporter, $user->getKey(), new SubmitRegistrationData(
                    organizationName: fake()->boolean(30) ? fake()->company() : null,
                    billingRecipientName: $user->name,
                    billingEmail: $user->email,
                    billingAddressLine: fake()->streetAddress(),
                    billingPostalCode: fake()->postcode(),
                    billingCity: fake()->city(),
                    billingCountryCode: 'DE',
                ));

                // Every second invoice is settled by bank transfer through
                // the real adapter chain; the rest stay open receivables.
                if ($index % 2 === 0) {
                    $order = Order::query()
                        ->where('settles_id', $registration->id)
                        ->first();

                    if ($order !== null) {
                        $this->bookPayment->handle($order);
                    }
                }
            });

        $this->seedCreditNote();
        $this->seedDunning($brand);
    }

    /**
     * One credit note against a paid order — the only legal way to correct an
     * issued invoice (ADR-0031), and the row the credit-note number range
     * exists for.
     */
    private function seedCreditNote(): void
    {
        if (CreditNote::query()->exists()) {
            return;
        }

        $invoice = Invoice::query()
            ->whereIn('order_id', Order::query()->where('status', OrderStatus::Paid)->select('id'))
            ->first();

        if ($invoice === null) {
            return;
        }

        /** @var Order|null $order */
        $order = Order::query()->whereKey($invoice->order_id)->first();

        if ($order === null) {
            return;
        }

        $this->issueCreditNote->handle(
            $order,
            IssueCreditNote::REASON_REFUND,
            reasonNote: 'Cancelled within the withdrawal period.',
        );
    }

    /**
     * One receivable that went out three weeks ago and was never paid, then
     * a real dunning pass over it. The due date is back-dated rather than the
     * dunning level written directly: `RaiseDunningLevel` is what decides the
     * level from the age, emits `InvoiceOverdue`/`DunningLevelRaised` and
     * sends the reminder — writing the level by hand would produce a row that
     * looks right and behaves like nothing ever happened.
     */
    private function seedDunning(Brand $brand): void
    {
        $stale = Invoice::query()
            ->whereNull('overdue_at')
            ->whereIn('order_id', Order::query()->where('status', OrderStatus::Pending)->select('id'))
            ->first();

        $stale?->forceFill([
            'issued_on' => now()->subDays(35),
            'due_on' => now()->subDays(21),
        ])->save();

        $this->raiseDunning->handle($brand->getKey());
    }
}
