<?php

declare(strict_types=1);

namespace Konventix\Registration\Data;

use Konventix\Platform\Data\EndPriceData;
use Konventix\Platform\Models\Scopes\BrandScope;
use Konventix\Registration\Models\Quota;
use Konventix\Registration\Models\RegistrationType;
use Konventix\Registration\Offers\PublicOffers;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * Inertia props for the public registration form (`GET
 * /events/{event}/register/{type}`, ADR-0009). The single source for what
 * the page is allowed to know about the offer it renders — never an inline
 * `->map(fn ...)` array in the controller.
 *
 * `price` is the declaration and `endPrice` what it actually costs (#234,
 * D-COM-20) — the same pair `PublicOfferData` carries, computed by the same
 * Commerce arithmetic behind Platform's `Pricing\EndPriceCalculator`. This
 * is the page where a consumer concludes the contract, so PAngV (#3) wants
 * the end price here most of all; until #234 it printed "… € net" in hard
 * markup whatever the type declared.
 */
#[TypeScript]
final class RegistrationFormData extends Data
{
    public function __construct(
        public readonly string $registrationTypeId,
        public readonly string $eventId,
        public readonly string $name,
        public readonly string $settlement,
        public readonly string $confirmationGate,
        public readonly ?PriceDeclarationData $price,
        public readonly ?EndPriceData $endPrice,
        public readonly int $termsDays,

        /**
         * The largest quantity this form may offer (bugfix round
         * 2026-09-21, Task 19): the type's own ceiling, lowered to what the
         * quotas it draws on still have left. One means "no choice" and the
         * page shows no number input at all.
         */
        public readonly int $maxQuantity,
    ) {}

    public static function fromModel(RegistrationType $registrationType): self
    {
        return new self(
            registrationTypeId: $registrationType->id,
            eventId: $registrationType->event_id,
            name: $registrationType->name,
            settlement: $registrationType->settlement->value,
            confirmationGate: $registrationType->confirmation_gate->value,
            // The declared price is an intention, not a guarantee (ADR-0017):
            // the invoice's actual treatment is resolved by Commerce.
            price: $registrationType->price_declaration,
            // Through the same helper the landing page and `/shop` use, so
            // the three surfaces cannot print three different amounts for
            // one type — a batch of one. It resolves the calculator at call
            // time (`app()->bound()`), never holding it: this factory is
            // already the page's own read of the type (see
            // `maxQuantityFor()` below), and a constructor-injected
            // calculator would pin Commerce into every caller.
            endPrice: PublicOffers::endPricesFor([$registrationType])[0],
            termsDays: $registrationType->terms_days,
            maxQuantity: self::maxQuantityFor($registrationType),
        );
    }

    /**
     * `min(type cap, remaining quota)`, never below one.
     *
     * A quantity larger than what remains would only be refused by
     * `SubmitRegistration` a moment later, so the control never offers it.
     * The remaining capacity is the *smallest* of the type's quotas: they
     * are consumed all-or-nothing (D-REG-12), so the tightest one is the
     * ceiling. A type drawing on no quota is bounded by its own cap alone.
     *
     * Read through an explicit query rather than the relation property:
     * `Model::shouldBeStrict()` forbids lazy-loading an unloaded relation,
     * and the type may arrive here straight from route-model binding. The
     * floor of one keeps the form renderable while the sale is sold out —
     * the submission then queues on the waitlist, which is
     * `SubmitRegistration`'s answer, not this page's.
     */
    private static function maxQuantityFor(RegistrationType $registrationType): int
    {
        $cap = $registrationType->maxQuantityPerRegistration();

        if ($cap === 1) {
            return 1;
        }

        $remaining = $registrationType->quotas()
            ->withoutGlobalScope(BrandScope::class)
            ->get()
            ->map(fn (Quota $quota): int => $quota->remaining())
            ->min();

        return $remaining === null ? $cap : max(1, min($cap, $remaining));
    }
}
