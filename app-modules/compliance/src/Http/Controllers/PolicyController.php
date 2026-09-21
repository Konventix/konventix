<?php

declare(strict_types=1);

namespace Konventix\Compliance\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Konventix\Compliance\Http\Middleware\EnsureContractConsent;
use Konventix\Compliance\Models\Policy;
use Konventix\Compliance\Models\PolicyVersion;
use Konventix\Compliance\Support\OutstandingConsents;
use Konventix\Compliance\Support\PolicyMarkdown;
use Konventix\Platform\Brands\BrandContext;

/**
 * The public policy surface on the brand's own domain: the currently
 * published text of one policy, and the screen that asks for agreement when
 * the `require-consent` gate has blocked a route.
 *
 * Brand scoping is `BelongsToBrand`'s global scope everywhere except the two
 * reads that must see the operator's inherited texts as well: those go
 * through `Policy::resolveFor()`/`OutstandingConsents`, which escape it
 * deliberately and say why (ADR-0010, GitLab #76).
 *
 * Only a *published* version is ever readable, and only once its effective
 * date has arrived. A draft is not a legal text, and a version dated for
 * January must not be readable as the current terms in December.
 *
 * `effectiveAt` goes out as an ISO 8601 instant with its offset, never as a
 * calendar day: `effective_at` is stored in UTC, and a version an operator
 * in Berlin dated 2026-09-08T00:30 is 2026-09-07T22:30Z — printed as a bare
 * date it told every reader the text had been in effect since 7 September
 * (B5). The reader's zone is the browser's to know, so `policies/Show.vue`
 * resolves it with the shared `formatDateTime()`, inside a `<time datetime>`
 * carrying the instant itself.
 */
final class PolicyController
{
    public function show(string $slug, PolicyMarkdown $markdown, BrandContext $brandContext): Response
    {
        $brandId = $brandContext->id();

        abort_if($brandId === null, 404);

        // The brand's own text where it published one, the operator's
        // otherwise (GitLab #76) — `Policy::resolveFor()` is the single place
        // that decides, and the only one that escapes BrandScope for it.
        $policy = Policy::resolveFor($brandId, $slug);

        abort_if($policy === null, 404);

        $version = $policy->currentVersion();

        abort_if($version === null, 404);

        return Inertia::render('policies/Show', [
            'policy' => [
                'name' => $policy->name,
                'slug' => $policy->slug,
                // Which of the two texts this is. The page says so out loud:
                // "published by the operator" is a different statement from
                // "published by this brand", and a visitor comparing two
                // brands' terms deserves to know which one they are reading.
                'scope' => $policy->isOperatorWide() ? 'operator' : 'brand',
                'version' => $version->version,
                'effectiveAt' => $version->effective_at?->toIso8601String(),
                'requiresConsent' => $version->requires_consent,
                'html' => $markdown->toHtml($version->content),
            ],
        ]);
    }

    /**
     * The consent gate's landing page. Reachable directly as well: someone
     * who wants to re-read what they are being asked to agree to should not
     * have to trigger the redirect again.
     *
     * With nothing outstanding it sends the visitor on rather than showing an
     * empty form — that is also what makes the post-agreement redirect land
     * correctly when two tabs answer the same gate.
     *
     * Since ADR-0066 it also serves the mid-session gate
     * (`require-contract-consent`), which is why it carries two more props:
     * `actLabel`, the short scalar the gate's middleware parameter left in
     * the session naming what the person was about to do, and `notNowUrl`,
     * where the "Not now" control leaves to. One page for both cases
     * (D-CPL-15) — a second interstitial would be a second place to get a
     * legal screen wrong.
     */
    public function required(Request $request, PolicyMarkdown $markdown, OutstandingConsents $outstanding): Response|RedirectResponse
    {
        $subjectId = (string) $request->user()?->getAuthIdentifier();

        $versions = $outstanding->forSubject($subjectId);

        if ($versions->isEmpty()) {
            return redirect()->intended('/');
        }

        // ADR-0066 D-CPL-15: what the person was about to do, as the gate's
        // own middleware parameter left it in the session. A scalar, never a
        // Registration query from Compliance — this module does not know
        // that an event or a registration type exists, and must not learn.
        $act = $request->session()->get(EnsureContractConsent::ACT_SESSION_KEY);

        return Inertia::render('policies/Required', [
            'actLabel' => is_string($act) && $act !== '' ? $act : null,
            // Where "Not now" leaves to (D-CPL-15). The brand's front page:
            // the account, the tickets and the order history stay reachable,
            // because only the *act* is gated, never the person's own
            // records. Root-relative — a brand answers on several domains
            // (ADR-0001).
            'notNowUrl' => '/',
            'versions' => $versions->map(fn (PolicyVersion $version): array => [
                'id' => $version->getKey(),
                'name' => $version->policy->name,
                'slug' => $version->policy->slug,
                'version' => $version->version,
                'effectiveAt' => $version->effective_at?->toIso8601String(),
                'html' => $markdown->toHtml($version->content),
            ])->all(),
        ]);
    }
}
