<?php

declare(strict_types=1);

namespace Konventix\Compliance\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Konventix\Compliance\Events\ConsentPromptRaised;
use Konventix\Compliance\Support\OutstandingConsents;
use Konventix\Platform\Brands\BrandContext;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * The narrow re-consent gate, as a second alias (`require-contract-consent`)
 * beside `require-consent` (ADR-0066 §6.5, D-CPL-12/13/14).
 *
 * The difference from `EnsurePoliciesAccepted` is the question, not the
 * mechanism: this one asks `subjectHasOutstandingContract()` — the texts the
 * sale itself rests on (`LawfulBasis::Contract`) — and nothing else. A
 * consent-based required text is still the wider alias's business; standing
 * between somebody and a contract they are about to conclude is this one's.
 *
 * Applied **by the owning module** to the routes that module knows are
 * contract-creating, never centrally (ADR-0003), and never to `policies.*`,
 * the login routes, `/my/*` read surfaces or Support's report channel
 * (ADR-0063, T-9) — a gate in front of the channel by which somebody reports
 * that the gate is broken is the worst possible loop. A route-name test in
 * `tests/ContractReconsentGateTest.php` pins both halves of that list.
 *
 * Guests pass through: consent belongs to a subject. Routes that need both
 * put `auth` first — this middleware refuses to guess whether an
 * unauthenticated request should be logged in or blocked.
 *
 * **A POST is never put in `url.intended`.** Replaying a POST after an
 * interstitial is not something a consent screen may decide to do, which is
 * why the gate sits on the GET that leads to the act as well as on the POST
 * that performs it: the GET is what makes "agree and carry on" land where
 * the person was going. Where the act's own page is not the request that was
 * blocked — a checkout POSTs from a confirmation step it does not address —
 * the owning module names that page as a second middleware parameter, and
 * the gate remembers *it* instead. A page to come back to is not a POST to
 * replay.
 *
 * Both parameters are scalars supplied by the owning module's route file —
 * `require-contract-consent:your registration` or
 * `require-contract-consent:your order,commerce.cart.confirm`. Compliance
 * never queries Registration or Commerce to name what somebody was doing
 * (D-CPL-15); the interstitial reads the label back out of the session and
 * heads the page with it.
 */
final class EnsureContractConsent
{
    /**
     * Where the act's label is parked for `policies.required` to read.
     * Its sibling `compliance.consent_redirect` (Identity's login hand-off)
     * is a different key with a different owner and is untouched here.
     */
    public const string ACT_SESSION_KEY = 'compliance.consent_act';

    public function __construct(private readonly OutstandingConsents $outstanding) {}

    public function handle(Request $request, Closure $next, ?string $act = null, ?string $returnTo = null): Response
    {
        $user = $request->user();

        if ($user === null) {
            return $next($request);
        }

        if (! $this->outstanding->subjectHasOutstandingContract((string) $user->getAuthIdentifier())) {
            return $next($request);
        }

        if ($request->hasSession()) {
            $request->session()->forget(self::ACT_SESSION_KEY);

            if ($act !== null && $act !== '') {
                $request->session()->put(self::ACT_SESSION_KEY, $act);
            }

            $intended = $this->intendedFor($request, $returnTo);

            if ($intended !== null && ! $request->expectsJson()) {
                $request->session()->put('url.intended', $intended);
            }
        }

        // ADR-0059, spec §10: the counter subscribes to an event like every
        // other recorder. `BrandContext` is resolved at call time — this
        // class is built once per request but the binding is scoped
        // (.ai/rules/providers.md). No brand resolved means no bounded label
        // to count under, and the redirect still happens.
        $brandId = app(BrandContext::class)->id();

        if ($brandId !== null) {
            event(new ConsentPromptRaised(
                brandId: $brandId,
                subjectId: (string) $user->getAuthIdentifier(),
                surface: ConsentPromptRaised::SURFACE_ROUTE_GATE,
                reason: ConsentPromptRaised::REASON_CONTRACT,
            ));
        }

        return redirect()->route('policies.required');
    }

    /**
     * The page to come back to, or null when there is none worth keeping.
     *
     * A GET is its own answer. Anything else has one only where the owning
     * module named a GET route to return to, and that route is resolved with
     * the parameters the blocked request already carries — `/pay/order/{order}/start`
     * comes back to `/pay/order/{order}/confirm`, the page the payer was
     * actually looking at. A name that does not resolve is dropped rather
     * than guessed at: landing somewhere wrong is worse than landing on the
     * brand's front page.
     */
    private function intendedFor(Request $request, ?string $returnTo): ?string
    {
        if ($request->isMethod('GET')) {
            return $request->fullUrl();
        }

        if ($returnTo === null || $returnTo === '') {
            return null;
        }

        try {
            return route($returnTo, $request->route()?->parameters() ?? [], absolute: false);
        } catch (Throwable) {
            return null;
        }
    }
}
