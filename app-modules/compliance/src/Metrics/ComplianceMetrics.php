<?php

declare(strict_types=1);

namespace Konventix\Compliance\Metrics;

use Konventix\Compliance\Events\ConsentPromptRaised;
use Konventix\Platform\Observability\MetricDefinition;
use Konventix\Platform\Observability\MetricKind;

/**
 * Compliance's first entries in the metric catalogue (ADR-0059, ADR-0066
 * spec §10). Counters are incremented by the listeners in
 * `src/Listeners/Metrics` — except `policy_binding_matches_total`, which
 * `Bindings\PolicyResolver` writes itself, because a resolution is not an
 * event and inventing one per render would cost a dispatch on every purchase
 * page. The gauge is answered by `ComplianceGauges` at scrape time.
 *
 * **Every label here is a bounded set** (T-11): surface, reason, kind. Never
 * a policy slug and never a condition key — both are operator-defined, so
 * either one would make the cardinality of these series a function of how
 * many texts an operator happens to have written.
 *
 * There is deliberately **no refusal counter**. The rate at which people
 * decline is derived on the dashboard from prompts against grants
 * (D-CPL-17); the interstitial's "Not now" control increments nothing of its
 * own, because a consent screen with only one way out is not a choice and a
 * metric that counted only the refusals would invite treating it as one.
 */
final class ComplianceMetrics
{
    /** @return list<MetricDefinition> */
    public static function definitions(): array
    {
        return [
            new MetricDefinition(
                'consent_prompts_total',
                MetricKind::Counter,
                'Times a subject was asked to agree to something, by surface and reason.',
                ['brand', 'surface', 'reason'],
            ),
            new MetricDefinition(
                'consent_grants_total',
                MetricKind::Counter,
                'Agreements recorded, by the surface they were captured on.',
                ['brand', 'surface'],
            ),
            new MetricDefinition(
                'consent_withdrawals_total',
                MetricKind::Counter,
                'Agreements withdrawn.',
                ['brand'],
            ),
            new MetricDefinition(
                'policy_binding_matches_total',
                MetricKind::Counter,
                'Policy bindings that matched a resolution, by kind — whether a bound text is actually being asked for.',
                ['brand', 'kind'],
            ),
            new MetricDefinition(
                'outstanding_contract_subjects',
                MetricKind::Gauge,
                'Subjects of the brand who still owe agreement to a published contract text.',
                ['brand'],
            ),
        ];
    }

    /**
     * `capture_context` mapped onto `consent_prompts_total`'s bounded
     * `surface` set.
     *
     * `capture_context` is the coarse label a consent row has always
     * carried, written by whichever surface captured the agreement, so it
     * has grown a handful of spellings (`registration_form`, `checkout`,
     * `consent_form`, `login`). Folding them onto
     * `ConsentPromptRaised`'s four surfaces — rather than widening the label
     * set — is the whole point of having both series: the refusal rate is
     * derived by dividing one by the other (D-CPL-17), and two sets that did
     * not line up would make that division meaningless.
     *
     * `consent_form` becomes `route_gate` because that is where a gated
     * prompt lands: the interstitial is the page a `route_gate` prompt sends
     * somebody to, and a grant captured there is that prompt's answer.
     * Anything unrecognised becomes `other`, exactly as an unlisted route
     * name does on the duration histogram.
     *
     * @return 'login'|'checkout'|'registration'|'route_gate'|'other'
     */
    public static function surfaceForCaptureContext(string $captureContext): string
    {
        return match (true) {
            str_contains($captureContext, 'checkout') => ConsentPromptRaised::SURFACE_CHECKOUT,
            str_contains($captureContext, 'registration') => ConsentPromptRaised::SURFACE_REGISTRATION,
            str_contains($captureContext, 'login') => ConsentPromptRaised::SURFACE_LOGIN,
            str_contains($captureContext, 'consent') => ConsentPromptRaised::SURFACE_ROUTE_GATE,
            default => 'other',
        };
    }
}
