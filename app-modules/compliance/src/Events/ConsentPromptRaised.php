<?php

declare(strict_types=1);

namespace Konventix\Compliance\Events;

/**
 * Somebody was asked to agree to something (spec §8, §10).
 *
 * Emitted wherever a consent control or an interstitial is actually put in
 * front of a subject — the login flag, the checkout slot, the registration
 * form, the route gate. It exists so the metric has an event to subscribe to
 * like every other (ADR-0059): a counter incremented from inside a
 * controller would drift from the surfaces that do the asking.
 *
 * Its counterpart is `ConsentGranted`. The **refusal rate is derived** from
 * the two series on the dashboard (D-CPL-17) — the interstitial's "Not now"
 * control has no counter of its own, because a consent screen with only one
 * way out is not a choice and a metric that only counted refusals would
 * invite treating it as one.
 *
 * `surface` and `reason` are the two bounded label sets of
 * `consent_prompts_total`; the constants below are the whole vocabulary, and
 * no policy slug ever joins them (T-11).
 *
 * Payload is scalars only (ADR-0004). Not auditable: being *asked* is not a
 * privileged act — agreeing is, and that is what `ConsentGranted` and the
 * consent row record.
 */
final readonly class ConsentPromptRaised
{
    public const string SURFACE_LOGIN = 'login';

    public const string SURFACE_CHECKOUT = 'checkout';

    public const string SURFACE_REGISTRATION = 'registration';

    public const string SURFACE_ROUTE_GATE = 'route_gate';

    /** The narrow question: an outstanding `lawful_basis = contract` text. */
    public const string REASON_CONTRACT = 'contract';

    /** The wider one: any required text the subject still owes. */
    public const string REASON_REQUIRED = 'required';

    public function __construct(
        public string $brandId,
        public string $subjectId,
        public string $surface,
        public string $reason,
    ) {}
}
