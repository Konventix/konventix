<?php

declare(strict_types=1);

namespace Konventix\Registration\Actions;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Konventix\Platform\Models\Scopes\BrandScope;
use Konventix\Registration\Actions\Concerns\ConsumesQuotas;
use Konventix\Registration\Data\SubmitRegistrationData;
use Konventix\Registration\Enums\ConfirmationGate;
use Konventix\Registration\Enums\EntryConditionAssertionState;
use Konventix\Registration\Enums\PaymentStatus;
use Konventix\Registration\Enums\RegistrationApproval;
use Konventix\Registration\Enums\RegistrationSettlement;
use Konventix\Registration\Enums\RegistrationStatus;
use Konventix\Registration\Enums\RegistrationVisibility;
use Konventix\Registration\Enums\WaitlistState;
use Konventix\Registration\Events\RegistrationConsentGiven;
use Konventix\Registration\Events\RegistrationEntryConditionAsserted;
use Konventix\Registration\Events\RegistrationSubmitted;
use Konventix\Registration\Events\WaitlistEntryConverted;
use Konventix\Registration\Models\EntryCondition;
use Konventix\Registration\Models\EntryConditionAssertion;
use Konventix\Registration\Models\InvitationToken;
use Konventix\Registration\Models\Quota;
use Konventix\Registration\Models\Registration;
use Konventix\Registration\Models\RegistrationType;
use Konventix\Registration\Models\WaitlistEntry;
use RuntimeException;

/**
 * Accepts a registration form and persists it (ADR-0005, SPEC.md "Owns" —
 * "the approval workflow — submission ..."). Validates visibility, the
 * sales window (#218) and account (D-REG-08 —
 * `docs/inventory/decision-log.md` Round 5: registrants must hold an
 * account), consumes the type's quotas, and emits `RegistrationSubmitted`.
 *
 * A submission buys `quantity` units (Task 19, bugfix round 2026-09-21):
 * the type's `max_quantity_per_registration` is the ceiling, the quotas are
 * consumed `quantity` times all-or-nothing, and asking for more than remains
 * is a `quantity` form error naming what is left rather than a waitlist
 * entry — the waitlist keeps its one-unit semantics, so only a submission
 * that finds *nothing* left queues.
 *
 * When `confirmation_gate: on_submit`, this Action confirms the
 * registration in the same transaction by calling `ConfirmRegistration`
 * (a nested `DB::transaction` becomes a savepoint — Laravel handles this
 * natively) — the gate says confirmation does not wait for approval or
 * payment, whatever the settlement. `confirmation_gate: on_payment` types
 * instead emit `RegistrationSettlementRequested` here at submission (the
 * registrant must be able to pay before anything can confirm); every other
 * gate emits at confirmation — see `ConfirmRegistration`.
 */
final class SubmitRegistration
{
    use ConsumesQuotas;

    public function __construct(
        private readonly ConfirmRegistration $confirmRegistration,
        private readonly RequestSettlement $requestSettlement,
    ) {}

    public function handle(
        RegistrationType $registrationType,
        ?string $registrantUserId,
        SubmitRegistrationData $data,
        ?string $invitationToken = null,
        ?WaitlistEntry $fromWaitlist = null,
    ): Registration {
        return DB::transaction(function () use ($registrationType, $registrantUserId, $data, $invitationToken, $fromWaitlist): Registration {
            if ($fromWaitlist === null) {
                $this->guardVisibility($registrationType, $invitationToken);
                $this->guardSalesWindow($registrationType);
            }
            $this->guardAccount($registrantUserId);
            $this->guardBillingParty($registrationType, $data);

            // ADR-0066 §6.2: which declarations this type demands cannot be
            // written into `SubmitRegistrationData`'s static ruleset either
            // — the same reason the billing party is guarded here. A
            // waitlist conversion is exempt for the same reason it skips
            // the visibility and window guards: the spot was promised, and
            // the queue carries no tick (see `writeEntryConditionAssertions`).
            $conditions = $this->entryConditionsFor($registrationType);

            if ($fromWaitlist === null) {
                $this->guardEntryConditions($conditions, $data);
            }

            // A waitlist offer held exactly one unit (D-REG-12), so a
            // conversion buys exactly one — the queue promises a spot, not
            // a basket.
            $quantity = $fromWaitlist === null ? $data->quantity : 1;

            // A waitlist conversion consumed its quota units at offer time
            // (the hold — D-REG-12); consuming again would double-book.
            if ($fromWaitlist === null) {
                $this->guardQuantity($registrationType, $quantity);
                $this->consumeQuotas($registrationType, $quantity);
            }

            $registration = Registration::create([
                'registration_type_id' => $registrationType->id,
                'event_id' => $registrationType->event_id,
                'registrant_user_id' => $registrantUserId,
                'status' => RegistrationStatus::Submitted,
                'quantity' => $quantity,
                'payment_status' => $registrationType->settlement === RegistrationSettlement::Free
                    ? PaymentStatus::None
                    : PaymentStatus::Pending,
                'organization_name' => $data->organizationName,
                'billing_recipient_name' => $data->billingRecipientName,
                'billing_email' => $data->billingEmail,
                'billing_address_line' => $data->billingAddressLine,
                'billing_postal_code' => $data->billingPostalCode,
                'billing_city' => $data->billingCity,
                'billing_country_code' => $data->billingCountryCode,
                'billing_vat_id' => $data->billingVatId,
                'submitted_at' => now(),
            ]);

            // Already have it — avoids ConfirmRegistration's loadMissing()
            // re-querying a relation this Action already holds.
            $registration->setRelation('registrationType', $registrationType);

            event(new RegistrationSubmitted(
                registrationId: $registration->id,
                registrationTypeId: $registrationType->id,
                eventId: $registrationType->event_id,
                brandId: $registration->brand_id,
                registrantUserId: $registration->registrant_user_id,
                settlement: $registrationType->settlement->value,
                confirmationGate: $registrationType->confirmation_gate->value,
                submittedAt: $registration->submitted_at->toIso8601String(),
            ));

            // The evidence, inside the same transaction as the act it
            // belongs to: one row per condition, with the wording that was
            // shown (T-6), then one event per condition and one declaration
            // of the legal texts for Compliance to resolve (D-CPL-10).
            $this->writeEntryConditionAssertions($registration, $registrationType, $conditions, $data, $fromWaitlist !== null);

            if ($fromWaitlist !== null) {
                $fromWaitlist->forceFill([
                    'state' => WaitlistState::Converted,
                    'converted_registration_id' => $registration->id,
                ])->save();

                event(new WaitlistEntryConverted(
                    waitlistEntryId: $fromWaitlist->id,
                    registrationId: $registration->id,
                    brandId: $registration->brand_id,
                    convertedAt: now()->toIso8601String(),
                ));
            }

            // An on_payment gate needs an order before anything can confirm:
            // the settlement request goes out at submission. Every other
            // gate requests settlement at confirmation instead (the two
            // emission points partition on the gate — no double emission).
            if (
                $registrationType->confirmation_gate === ConfirmationGate::OnPayment
                && $registrationType->settlement->requestsSettlement()
            ) {
                // #218: a fresh submission is the one settlement request
                // Commerce may refuse on window grounds. A waitlist
                // conversion carries no window (see RequestSettlement), and
                // the same flag decides whether a refusal may be read as one.
                $windowBound = $fromWaitlist === null;

                try {
                    $this->requestSettlement->handle(
                        $registration,
                        $registrationType,
                        enforcesSalesWindow: $windowBound,
                    );
                } catch (RuntimeException $exception) {
                    $this->convertSettlementRefusal($exception, $registrationType, $windowBound);
                }
            }

            // Approval is a precondition, not a gate: a type declaring
            // `approval: required` never confirms at submission even if its
            // gate says on_submit — that combination is a misconfigured type,
            // and the safe reading is "wait for approval".
            if (
                $registrationType->confirmation_gate === ConfirmationGate::OnSubmit
                && $registrationType->approval === RegistrationApproval::None
            ) {
                $registration = $this->confirmRegistration->handle($registration);
            }

            return $registration;
        });
    }

    /**
     * The settlement request runs through Commerce's synchronous listener,
     * inside this transaction. If Commerce refuses the request because the
     * sales window closed between this Action's own check and the listener's
     * — a race of milliseconds, but the money path — the registrant must see
     * the refusal the form shows, not a 500. Rolling back with nothing placed
     * is the correct outcome either way.
     *
     * The condition is re-evaluated rather than matched on Commerce's
     * exception class: ADR-0004 forbids importing it (enforced by
     * `tests/Unit/ArchitectureTest.php`), and "the window has closed since"
     * is the same fact without the dependency.
     *
     * Both conditions are required, and `$windowBound` is the load-bearing
     * one (fix round 2, review finding N1). `RuntimeException` is a wide net —
     * `QueryException` is a `PDOException` is a `RuntimeException`, so a
     * deadlock or a constraint violation anywhere in the listener chain lands
     * here. Re-reading the window is only evidence of a race where
     * `guardSalesWindow()` ran microseconds earlier and passed. A waitlist
     * conversion skips that guard by design and its window is closed *by
     * construction* (D-REG-12), so on that path a false `isOnSale()` says
     * nothing at all — converting there would tell the registrant the one
     * thing that is certainly not the cause and throw the real one away.
     * Everything else rethrows untouched.
     */
    private function convertSettlementRefusal(
        RuntimeException $exception,
        RegistrationType $registrationType,
        bool $windowBound,
    ): never {
        if (! $windowBound || $registrationType->isOnSale()) {
            throw $exception;
        }

        throw ValidationException::withMessages([
            'registrationType' => __('This registration is no longer on sale.'),
        ]);
    }

    /**
     * `public` types need nothing; `link` and `invite_only` types need a
     * usable invitation token belonging to the type (Round 6 D-COM-02).
     * A consumed use is burned inside this transaction under lock, so a
     * single-use invitation cannot be double-spent by concurrent submits.
     */
    private function guardVisibility(RegistrationType $registrationType, ?string $invitationToken): void
    {
        if ($registrationType->visibility === RegistrationVisibility::Public) {
            return;
        }

        $token = $invitationToken === null ? null : InvitationToken::query()
            ->where('registration_type_id', $registrationType->id)
            ->where('token', $invitationToken)
            ->lockForUpdate()
            ->first();

        if ($token === null || ! $token->isUsable()) {
            throw new RuntimeException(sprintf(
                'RegistrationType [%s] requires a valid invitation token (visibility: %s).',
                $registrationType->id,
                $registrationType->visibility->value,
            ));
        }

        $token->increment('uses');
    }

    /**
     * #218 (#216 draft i): the sales window is enforced here, server-side and
     * independently of the waiting room — #216 is explicit that the room
     * shapes the queue and is never the access control. The landing page
     * already hides a type whose sale is over, but a bookmark, a stale tab or
     * a direct POST arrives here regardless.
     *
     * A `ValidationException` rather than a `RuntimeException`, so the
     * registration form shows a refusal the way it shows a missing billing
     * field — the same shape `guardBillingParty()` uses.
     *
     * A waitlist conversion is exempt (the caller skips this guard): the spot
     * was promised and its quota held at offer time, and a queue that drains
     * after the sale ends is the point of the queue (D-REG-12).
     */
    private function guardSalesWindow(RegistrationType $registrationType): void
    {
        if ($registrationType->isOnSale()) {
            return;
        }

        throw ValidationException::withMessages([
            'registrationType' => $registrationType->sales_start !== null && now()->lessThan($registrationType->sales_start)
                ? __('This registration is not on sale yet.')
                : __('This registration is no longer on sale.'),
        ]);
    }

    /**
     * The two upper bounds on a buyer-chosen quantity (Task 19), neither of
     * which `SubmitRegistrationData`'s static ruleset can see: the type's
     * own ceiling, and what its quotas still have left.
     *
     * Both refuse as a `quantity` form error, the shape
     * `guardBillingParty()` uses — the number input is right there and the
     * buyer can act on either message.
     *
     * The capacity check deliberately does **not** cover the sold-out case.
     * A quota with nothing left falls through to `consumeQuotas()`, whose
     * `QuotaExhaustedException` the controller turns into a waitlist entry
     * (D-REG-12) — telling someone "only 0 left" instead of queueing them
     * would drop the one thing the module offers a sold-out sale. The
     * quotas are read under the same lock `consumeQuotas()` takes a moment
     * later, inside the caller's transaction, so the number named here is
     * the number that is then consumed against.
     */
    private function guardQuantity(RegistrationType $registrationType, int $quantity): void
    {
        $cap = $registrationType->maxQuantityPerRegistration();

        if ($quantity > $cap) {
            throw ValidationException::withMessages([
                'quantity' => __('At most :max per registration.', ['max' => $cap]),
            ]);
        }

        if ($quantity === 1) {
            return;
        }

        $remaining = $registrationType->quotas()
            ->lockForUpdate()
            ->get()
            ->map(fn (Quota $quota): int => $quota->remaining())
            ->min();

        if ($remaining !== null && $remaining > 0 && $remaining < $quantity) {
            throw ValidationException::withMessages([
                'quantity' => __('Only :remaining left.', ['remaining' => $remaining]),
            ]);
        }
    }

    /**
     * The conditions this type demands right now, in the operator's order.
     *
     * Read through an explicit query rather than the relation property:
     * `Model::shouldBeStrict()` forbids lazy-loading an unloaded relation,
     * the type may arrive straight from route-model binding, and a waitlist
     * conversion runs this on the admin host where `BrandScope` hardens
     * every brand-owned query to nothing (.ai/rules) — so the scope is
     * escaped on the *related* model as well as on the parent.
     *
     * Archived conditions are not filtered out: a type still pointing at one
     * still demands it, and hiding it here would silently stop asking
     * without anybody deciding to.
     *
     * @return Collection<int, EntryCondition>
     */
    private function entryConditionsFor(RegistrationType $registrationType): Collection
    {
        return $registrationType->entryConditions()
            ->withoutGlobalScope(BrandScope::class)
            ->get();
    }

    /**
     * Every condition the type declares has to be in the posted list
     * (D-REG-21, spec §6.2).
     *
     * A declaration nobody made is not a declaration, so the submission is
     * refused rather than written without one — keyed `entryConditions.{id}`
     * so the message lands on the checkbox that raised it (ADR-0055). Where
     * the type names an `alternative_registration_type_id`, the page renders
     * a "buy the regular ticket instead" link beside that error: a dead end
     * at the checkbox loses a sale the full-price type would have taken.
     *
     * @param  Collection<int, EntryCondition>  $conditions
     */
    private function guardEntryConditions(Collection $conditions, SubmitRegistrationData $data): void
    {
        if ($conditions->isEmpty()) {
            return;
        }

        $accepted = array_flip($data->acceptedEntryConditionIds);

        $missing = $conditions
            ->reject(fn (EntryCondition $condition): bool => isset($accepted[(string) $condition->getKey()]))
            ->mapWithKeys(fn (EntryCondition $condition): array => [
                'entryConditions.'.$condition->getKey() => __('Please confirm: :text', ['text' => $condition->assertion_text]),
            ])
            ->all();

        if ($missing !== []) {
            throw ValidationException::withMessages($missing);
        }
    }

    /**
     * One assertion per condition, whatever the quantity (D-REG-20): the
     * registrant declares once, for the contractual act. The door checks per
     * person instead, because the label rides on every admission.
     *
     * The snapshots are the point (T-6): the condition's wording may be
     * reworded tomorrow, the evidence may not change under the person who
     * gave it.
     *
     * A waitlist conversion writes nothing. The queue holds a billing
     * snapshot, not a declaration — nobody ticked a box when they joined —
     * so its registration shows *not asserted* in the console and an
     * operator waives or asks, exactly as for a condition added to a live
     * type (D-REG-19). Collecting the declaration on the offer link is the
     * honest follow-up and belongs with the per-holder declarations (§15).
     *
     * `RegistrationConsentGiven` goes out once, with the binding context
     * Compliance resolves into policy versions (D-CPL-10, Task 32). This
     * module builds the bag and never interprets it; until that listener
     * exists nothing consumes the event.
     *
     * @param  Collection<int, EntryCondition>  $conditions
     */
    private function writeEntryConditionAssertions(
        Registration $registration,
        RegistrationType $registrationType,
        Collection $conditions,
        SubmitRegistrationData $data,
        bool $fromWaitlist,
    ): void {
        $assertedIds = [];

        if (! $fromWaitlist) {
            $accepted = array_flip($data->acceptedEntryConditionIds);

            foreach ($conditions as $condition) {
                if (! isset($accepted[(string) $condition->getKey()])) {
                    continue;
                }

                $assertion = new EntryConditionAssertion([
                    'registration_id' => $registration->getKey(),
                    'entry_condition_id' => $condition->getKey(),
                    'subject_user_id' => $registration->registrant_user_id,
                    'asserted_at' => now(),
                    'assertion_text_snapshot' => $condition->assertion_text,
                    'verification_snapshot' => $condition->verification,
                    'state' => EntryConditionAssertionState::Asserted,
                ]);

                $assertion->forceFill(['brand_id' => $registration->brand_id])->save();

                $assertedIds[] = (string) $condition->getKey();

                event(new RegistrationEntryConditionAsserted(
                    assertionId: (string) $assertion->getKey(),
                    registrationId: (string) $registration->getKey(),
                    entryConditionId: (string) $condition->getKey(),
                    entryConditionKey: $condition->key,
                    brandId: $registration->brand_id,
                    subjectUserId: (string) $registration->registrant_user_id,
                    verification: $condition->verification->value,
                    assertedAt: $assertion->asserted_at->toIso8601String(),
                ));
            }
        }

        event(new RegistrationConsentGiven(
            brandId: $registration->brand_id,
            subjectId: (string) $registration->registrant_user_id,
            captureContext: 'registration',
            acceptedAt: now()->toIso8601String(),
            bindingContext: [
                'brand_id' => $registration->brand_id,
                'event_id' => $registrationType->event_id,
                'registration_type_ids' => [(string) $registrationType->getKey()],
                'entry_condition_ids' => $assertedIds,
                'registration_id' => (string) $registration->getKey(),
            ],
        ));
    }

    private function guardAccount(?string $registrantUserId): void
    {
        // D-REG-08: registrants must hold an account. Guest registration is
        // deferred; the controller's `auth` middleware is the first line of
        // defence, this is the Action's own defence in depth.
        if ($registrantUserId === null || $registrantUserId === '') {
            throw new RuntimeException('A registration requires an authenticated account (D-REG-08).');
        }
    }

    /**
     * A legally valid German invoice needs the recipient's postal address,
     * so `settlement: invoice` types require the full billing party at
     * submission. Enforced here rather than in `SubmitRegistrationData`'s
     * static rules because the required-ness depends on which type the URL
     * addressed. `instant` types deliberately stay lenient: a B2C checkout
     * receipt needs no postal address.
     */
    private function guardBillingParty(RegistrationType $registrationType, SubmitRegistrationData $data): void
    {
        if ($registrationType->settlement !== RegistrationSettlement::Invoice) {
            return;
        }

        $missing = array_keys(array_filter([
            'billingRecipientName' => $data->billingRecipientName === null && $data->organizationName === null,
            'billingEmail' => $data->billingEmail === null,
            'billingAddressLine' => $data->billingAddressLine === null,
            'billingPostalCode' => $data->billingPostalCode === null,
            'billingCity' => $data->billingCity === null,
            'billingCountryCode' => $data->billingCountryCode === null,
        ]));

        if ($missing !== []) {
            throw ValidationException::withMessages(array_fill_keys(
                $missing,
                __('This field is required for invoice-settled registrations.'),
            ));
        }
    }
}
