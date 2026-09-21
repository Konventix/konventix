<?php

declare(strict_types=1);

namespace Konventix\Registration\Data;

use Konventix\Platform\Data\EndPriceData;
use Konventix\Registration\Enums\RegistrationApproval;
use Konventix\Registration\Models\RegistrationType;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * One offer as a visitor sees it on the public event landing page, before
 * they have an account (ADR-0009 — the page is never handed a raw model or
 * an inline `->map(fn ...)` array).
 *
 * Deliberately narrower than `RegistrationFormData`: `confirmationGate` and
 * `termsDays` describe what happens *after* someone commits, and a landing
 * page that recites them is answering a question nobody has asked yet. What
 * survives is what decides a click — the name, the price, whether a
 * reviewer stands between the form and a place at the event, and since #218
 * whether it is on sale at all.
 *
 * `salesStart` is an ISO-8601 instant rather than a formatted date: the
 * announcement reads in the visitor's own timezone, which only the browser
 * knows (the slot renders server-side for a page anyone in the world may
 * open). A type whose sale has *ended* never reaches this class — the slot
 * drops it — so `onSale: false` always means "not yet".
 *
 * `eventName` is null on the event landing page, where the reader is
 * already standing on the event and repeating its name would be noise, and
 * always a real name on `/shop` (#240), which is brand-wide and
 * event-independent: there "Weekend Ticket" alone does not say what it is a
 * ticket to. It comes from this module's own `EventSummary` read model,
 * never from Event's tables (ADR-0004).
 *
 * The shop contribution guarantees it rather than tolerating a gap (review
 * finding 3): its query selects types whose event has a summary that is
 * public and unarchived, so an offer whose event this module cannot name is
 * not listed at all instead of rendering an unlabelled card. The nullability
 * of the field is the landing page's, not a degraded shop card.
 *
 * `eventSlug` follows `eventName` exactly (bugfix round 2026-09-21, Task
 * 17c): set from the same summary row on `/shop`, where the card links the
 * event's name to its page, and null on the landing page, which is that
 * page already.
 *
 * `endPrice` is what the offer actually costs (#234, D-COM-20): `price` is
 * Registration's *declaration* — a net amount, a tax class and a display
 * choice — and only Commerce can turn that into money (ADR-0017). It
 * arrives computed, through Platform's `Pricing\EndPriceCalculator`, which
 * the caller resolves once for the whole list; this class takes a value and
 * never reaches into the container (`.ai/rules/data.md`). Null where no end
 * price could be computed — nothing bound, a brand with no settlement
 * identity — and the surface then prints the declared net amount with its
 * "excl. VAT" label, which is what every one of these cards did before.
 */
#[TypeScript]
final class PublicOfferData extends Data
{
    public function __construct(
        public readonly string $registrationTypeId,
        public readonly string $name,
        public readonly string $url,
        public readonly ?PriceDeclarationData $price,
        public readonly bool $requiresApproval,
        public readonly bool $onSale = true,
        public readonly ?string $salesStart = null,
        public readonly ?string $eventName = null,
        public readonly ?string $eventSlug = null,
        public readonly ?EndPriceData $endPrice = null,
    ) {}

    /**
     * Named `of*` rather than `from*` on purpose: laravel-data routes every
     * public static `from*` method through its magic-creation resolver
     * (.ai/rules/data.md).
     */
    public static function ofType(
        RegistrationType $registrationType,
        ?string $eventName = null,
        ?string $eventSlug = null,
        ?EndPriceData $endPrice = null,
    ): self {
        return new self(
            registrationTypeId: $registrationType->id,
            name: $registrationType->name,
            // Relative, because the landing page is rendered on the brand's
            // own host and an absolute URL would pin the link to whichever
            // domain the request happened to arrive on.
            url: route('registration.show', [
                'event_ulid' => $registrationType->event_id,
                'type' => $registrationType->id,
            ], absolute: false),
            // An intention, not a guarantee (ADR-0017): Commerce resolves the
            // actual tax treatment when it settles.
            price: $registrationType->price_declaration,
            requiresApproval: $registrationType->approval === RegistrationApproval::Required,
            onSale: $registrationType->isOnSale(),
            salesStart: $registrationType->sales_start?->toIso8601String(),
            eventName: $eventName,
            eventSlug: $eventSlug,
            endPrice: $endPrice,
        );
    }
}
