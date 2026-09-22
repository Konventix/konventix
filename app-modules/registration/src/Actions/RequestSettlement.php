<?php

declare(strict_types=1);

namespace Konventix\Registration\Actions;

use Konventix\Platform\Models\Scopes\BrandScope;
use Konventix\Registration\Data\PriceDeclarationData;
use Konventix\Registration\Events\RegistrationSettlementRequested;
use Konventix\Registration\Models\Registration;
use Konventix\Registration\Models\RegistrationType;
use RuntimeException;

/**
 * Builds and emits `RegistrationSettlementRequested` for a registration
 * whose settlement moves money through the platform (ADR-0026 inverse:
 * `instant`/`invoice` only — the callers guard on
 * `RegistrationSettlement::requestsSettlement()` before invoking this).
 *
 * Not transactional itself: it always runs inside the calling Action's
 * `DB::transaction` (SubmitRegistration for `on_payment` gates,
 * ConfirmRegistration for every other gate, AmendRegistration for a priced
 * amendment).
 *
 * `$requestKey` defaults to the registration's own ULID, which is what the
 * two original call sites want and what makes existing orders backfill
 * cleanly. An amendment passes its own ULID instead so Commerce treats it as
 * a separate order rather than a replay (ADR-0036).
 *
 * `$enforcesSalesWindow` decides whether the type's sales window travels with
 * the request (#218, fix round 1). It is true for exactly one caller: a fresh
 * registration submitted by someone buying now, which is the only request
 * Commerce may refuse on window grounds. It is false — and both scalars go
 * out null, "not window-bound" — for a waitlist conversion (the spot was
 * promised and its quota held at offer time, D-REG-12), for a confirmation
 * after approval (the organizer let a straggler in on purpose) and for a
 * priced amendment (ADR-0036: the registration already exists and already
 * holds its quota). Registration is the authority on who may register;
 * Commerce enforces the window only for a fresh sale, and only where a payer
 * can still act on the refusal.
 *
 * `$lines` defaults to the type's price declaration, at the registration's
 * own quantity (Task 19): one line, `quantity` units of the type's per-unit
 * net. Commerce multiplies — the cart line and the order line both read
 * `unit_amount_net * quantity` — so the amount stays per unit here and the
 * cart can say "3 × 50,00". An amendment overrides
 * it with the delta's own lines: the added grants are priced independently of
 * what the type costs — crew that comes with an exhibitor package is free,
 * while a second booth is not — and `GrantData` carries no price of its own.
 *
 * `$price` overrides *which declaration* those defaults are built from, and
 * has exactly one caller: a waitlist conversion, which passes the
 * declaration the entry pinned when the registrant joined the queue
 * (D-0.8.2-2, #8). Null — every other caller, and any entry queued before
 * the column existed — reads the type's current declaration, which is what
 * this Action always did.
 *
 * ### Why the tax *class* is pinned with the amount, not re-read
 *
 * The amount and the tax class look like two questions, and the tempting
 * split is "pin the amount the registrant was promised, take the tax
 * treatment from the law at the moment of sale". With this codebase in
 * front of you that split buys nothing and costs something.
 *
 * Nothing pinned here can freeze any law. `TaxClass` is `standard|reduced`,
 * a property of the supply, never a percentage: `Commerce\Tax\GermanVat`
 * resolves the *rate* for a class at settlement from its own constants
 * ("a statutory rate change is a code change here"), and resolves the
 * *treatment* — §19 UStG, reverse charge — at settlement from the operator's
 * election and the billing party's VAT ID, evidence this module never sees
 * (ADR-0017, ADR-0030). A conversion settles at promotion, so both are
 * already the law of the day whatever travels in the pin. Re-reading the
 * class would not add currency to anything; it would only let an operator's
 * later reclassification of the type reach back into a promise.
 *
 * And it would break the promise where the promise is most visible. The
 * declaration carries `display`, and a type displaying gross showed the
 * registrant net + rate(class). Pin the net while the class moves and the
 * gross they are charged is not the gross they saw — the exact failure
 * D-0.8.2-2 exists to prevent, arrived at through the back door.
 *
 * So the whole value object travels. ADR-0017 made the declaration one
 * value object precisely so it would not be handled as three loose columns,
 * and pinning two thirds of it is that handling. What the registrant is
 * promised is *the offer*; what the state takes is Commerce's, resolved
 * fresh at settlement, and untouched by any of this.
 *
 * A type whose price declaration was set to null after the entry queued
 * (while its settlement stayed priced) used to throw here; with a pin it
 * settles at the pinned price instead, which is the same promise seen from
 * a misconfiguration. The `settlement` axis itself is deliberately *not*
 * pinned — it is read live off the type, so a type switched to `free` after
 * queuing converts free. That is a settlement-mode change, not a price
 * change, and D-0.8.2-2 does not reach it.
 */
final class RequestSettlement
{
    /**
     * @param  list<array{label: string, quantity: int, unit_amount_net: numeric-string, tax_class: string}>|null  $lines
     */
    public function handle(
        Registration $registration,
        RegistrationType $registrationType,
        ?string $requestKey = null,
        ?array $lines = null,
        bool $enforcesSalesWindow = false,
        ?PriceDeclarationData $price = null,
    ): void {
        $price ??= $registrationType->price_declaration;

        if ($price === null) {
            throw new RuntimeException(sprintf(
                'RegistrationType [%s] declares settlement [%s] but carries no price declaration (ADR-0017) — '
                .'a priced settlement without a price is a misconfigured type.',
                $registrationType->id,
                $registrationType->settlement->value,
            ));
        }

        event(new RegistrationSettlementRequested(
            registrationId: $registration->id,
            registrationTypeId: $registrationType->id,
            eventId: $registration->event_id,
            brandId: $registration->brand_id,
            registrantUserId: $registration->registrant_user_id,
            settlement: $registrationType->settlement->value,
            billingParty: [
                'organization_name' => $registration->organization_name,
                'recipient_name' => $registration->billing_recipient_name,
                'email' => $registration->billing_email,
                'address_line' => $registration->billing_address_line,
                'postal_code' => $registration->billing_postal_code,
                'city' => $registration->billing_city,
                'country_code' => $registration->billing_country_code,
                'vat_id' => $registration->billing_vat_id,
            ],
            lines: $lines ?? [
                [
                    'label' => $registrationType->name,
                    'quantity' => $registration->quantity,
                    'unit_amount_net' => $price->unitAmountNet,
                    'tax_class' => $price->taxClass->value,
                ],
            ],
            priceDisplay: $price->display->value,
            termsDays: $registrationType->terms_days,
            requestedAt: now()->toIso8601String(),
            requestKey: $requestKey ?? $registration->id,
            // #218: Commerce enforces the same window and may not read this
            // module's table to learn it (ADR-0004). Null means "not
            // window-bound" — see this class's docblock for which requests
            // are exempt and why.
            saleStartsAt: $enforcesSalesWindow ? $registrationType->sales_start?->toIso8601String() : null,
            saleEndsAt: $enforcesSalesWindow ? $registrationType->sales_end?->toIso8601String() : null,
            // ADR-0066 §7.2, D-CPL-06: what this purchase is *under*, so the
            // checkout's consent slot can resolve the texts bound to it.
            // Scalars, snake_cased, in the shape Compliance's resolver reads
            // — Commerce carries the bag from here to the order line and
            // interprets no key of it, and this module never learns that a
            // policy exists.
            bindingContext: [
                'event_id' => $registration->event_id,
                'registration_type_ids' => [$registrationType->id],
                'entry_condition_ids' => $this->entryConditionIdsFor($registrationType),
                'registration_id' => $registration->id,
            ],
        ));
    }

    /**
     * The entry conditions this type declares, as ids (ADR-0066 §4.2).
     *
     * Read off the type rather than off the registration's assertions,
     * because the bag describes *what is being bought*, which is a property
     * of the type. The bag is then frozen on the order line: a condition
     * added to the type afterwards does not retroactively change a purchase
     * somebody already made, which is what a consent record should quote
     * anyway.
     *
     * `withoutGlobalScope(BrandScope::class)` on the *related* model, not
     * merely on the parent: a confirmation performed from the operator
     * console runs on the admin host, where the scope hardens to zero rows
     * and this would silently come back empty
     * (.ai/rules/app-modules-src.md, #236).
     *
     * Empty for a type with no conditions — every type on every install that
     * has not defined one — and the resolution is then exactly what it was
     * before this existed.
     *
     * @return list<string>
     */
    private function entryConditionIdsFor(RegistrationType $registrationType): array
    {
        return array_values($registrationType->entryConditions()
            ->withoutGlobalScope(BrandScope::class)
            ->pluck('registration_entry_conditions.id')
            ->map(strval(...))
            ->all());
    }
}
