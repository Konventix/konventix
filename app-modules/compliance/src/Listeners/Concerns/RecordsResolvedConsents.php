<?php

declare(strict_types=1);

namespace Konventix\Compliance\Listeners\Concerns;

use Illuminate\Support\Carbon;
use Konventix\Compliance\Actions\GrantConsent;
use Konventix\Compliance\Bindings\BindingContext;
use Konventix\Compliance\Bindings\PolicyResolver;
use Konventix\Compliance\Models\PolicyVersion;

/**
 * Turning one declaration into Consent rows — shared by the checkout's
 * listener and the registration form's, because it is the same act recorded
 * from two surfaces (ADR-0066 §5.5, D-CPL-09/10).
 *
 * The resolution runs on the context the *page* rendered, carried on the
 * event, and is never re-derived from the brand: a list resolved a second
 * time from different facts would record agreement to texts nobody was
 * shown, which is exactly the drift D-CPL-07's two-step checkout exists to
 * prevent.
 *
 * Nothing is recorded for a text without a published, effective version —
 * the resolver drops those, for the reason `RecordCheckoutConsent` always
 * gave: a row pointing at an unpublished text would look like evidence and
 * be none.
 *
 * `GrantConsent` is idempotent per (subject, live version), so a
 * double-submitted form or a retried payment start is one agreement.
 */
trait RecordsResolvedConsents
{
    /**
     * @param  array<string, mixed>  $bag  the binding context as the event carried it
     */
    private function recordResolvedConsents(
        string $brandId,
        string $subjectId,
        string $captureContext,
        string $acceptedAt,
        array $bag,
    ): void {
        // Resolved at call time rather than constructor-injected: the event
        // dispatcher builds a listener with the container it was constructed
        // with, which under Octane is the worker's, not the request's
        // (.ai/rules/listeners.md).
        $context = BindingContext::ofArray($bag, $brandId);
        $texts = app(PolicyResolver::class)->textsFor($context);

        if ($texts === []) {
            return;
        }

        $grantConsent = app(GrantConsent::class);
        $snapshot = $context->toArray();
        $grantedAt = Carbon::parse($acceptedAt);

        foreach ($texts as $text) {
            // Escapes BrandScope: an operator-wide text's version carries no
            // brand at all (#76, ADR-0010).
            $version = PolicyVersion::withoutBrandScope()->find($text->versionId);

            if ($version === null) {
                continue;
            }

            $grantConsent(
                $subjectId,
                $version,
                $captureContext,
                $grantedAt,
                // The first binding that matched. A policy bound on two kinds
                // matches through one binding of each (AND across kinds,
                // D-CPL-03), and the column is singular because the question
                // it answers is "why was this asked" — which the snapshot
                // beside it answers in full. Null for an unbound policy,
                // which is still the normal case.
                $text->bindingIds[0] ?? null,
                $snapshot,
            );
        }
    }
}
