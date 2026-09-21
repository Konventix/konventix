<?php

declare(strict_types=1);

namespace Konventix\Registration\Consent;

use Konventix\Platform\Models\Scopes\BrandScope;
use Konventix\Platform\Slots\SlotRegistry;
use Konventix\Registration\Models\RegistrationType;

/**
 * The whole of what Registration knows about the consent control its public
 * form renders — Commerce's `Settlement\CheckoutConsentSlots` for the other
 * purchase path, and deliberately its twin (ADR-0066 §6.2, §7.1).
 *
 * Registration declares the slot `registration.consent`, because a page owns
 * the places other modules may add to; Compliance fills it, because
 * Registration may not read a policy row (ADR-0004). Two facts come back and
 * only two matter here: `required` — the registrant has to confirm before
 * the submission is accepted — and `outstandingUrl`, which the form's own
 * gate (`require-contract-consent`) settles before this page is reached.
 *
 * One reader for the page and for the Action, for the reason Commerce gives:
 * the rendering surface and the guard that refuses a submission must not
 * drift on what the contribution meant. The page renders `contributions()`;
 * `SubmitRegistration` asks `declarationRequired()` on the same context and
 * refuses without a tick.
 *
 * The context is the registration act's: the event, the type being bought
 * and the entry conditions the type declares — the same three facts
 * `RequestSettlement` puts in the bag that travels to the checkout, so the
 * registrant meets the same texts at both ends of the purchase. This module
 * interprets none of them on the way back.
 *
 * `SlotRegistry` is a boot-time singleton holding registrations only, so
 * constructor injection is safe (.ai/rules/providers.md); the props closures
 * it holds resolve their own scoped bindings per call.
 */
final class RegistrationConsentSlots
{
    public function __construct(private readonly SlotRegistry $slots) {}

    /**
     * @return list<array{module: string, component: string, props: array<string, mixed>}>
     */
    public function contributions(RegistrationType $type): array
    {
        return array_values($this->slots->for('registration.consent', [
            'eventId' => $type->event_id,
            'registrationTypeIds' => [(string) $type->getKey()],
            'entryConditionIds' => $this->entryConditionIdsOf($type),
        ])->map(fn (array $entry): array => [
            'module' => $entry['module'],
            'component' => $entry['component'],
            'props' => $entry['props'],
        ])->all());
    }

    /**
     * Whether a contribution says the registrant must confirm it before the
     * submission is accepted — false when nothing applies, which is what
     * keeps a brand with no published texts able to sell.
     */
    public function declarationRequired(RegistrationType $type): bool
    {
        foreach ($this->contributions($type) as $contribution) {
            if (($contribution['props']['required'] ?? false) === true) {
                return true;
            }
        }

        return false;
    }

    /**
     * `withoutGlobalScope(BrandScope::class)` on the related model: the
     * public form runs on a brand host where the scope resolves normally,
     * but `SubmitRegistration` is also reached from a console request, where
     * BrandScope hardens a brand-owned relation query to zero rows
     * (.ai/rules/app-modules-src.md). An empty list there would silently
     * resolve a narrower set of texts than the form showed.
     *
     * @return list<string>
     */
    private function entryConditionIdsOf(RegistrationType $type): array
    {
        return array_values($type->entryConditions()
            ->withoutGlobalScope(BrandScope::class)
            ->pluck('registration_entry_conditions.id')
            ->map(strval(...))
            ->all());
    }
}
