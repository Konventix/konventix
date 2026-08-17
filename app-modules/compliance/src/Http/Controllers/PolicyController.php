<?php

declare(strict_types=1);

namespace Konventix\Compliance\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Konventix\Compliance\Enums\PolicyVersionState;
use Konventix\Compliance\Models\Policy;
use Konventix\Compliance\Models\PolicyVersion;
use Konventix\Compliance\Support\OutstandingConsents;
use Konventix\Compliance\Support\PolicyMarkdown;

/**
 * The public policy surface on the brand's own domain: the currently
 * published text of one policy, and the screen that asks for agreement when
 * the `require-consent` gate has blocked a route.
 *
 * Brand scoping is `BelongsToBrand`'s global scope — this is a brand host, so
 * nothing here escapes it (ADR-0010).
 *
 * Only a *published* version is ever readable, and only once its effective
 * date has arrived. A draft is not a legal text, and a version dated for
 * January must not be readable as the current terms in December.
 */
final class PolicyController
{
    public function show(string $slug, PolicyMarkdown $markdown): Response
    {
        /** @var Policy|null $policy */
        $policy = Policy::query()->where('slug', $slug)->first();

        abort_if($policy === null, 404);

        /** @var PolicyVersion|null $version */
        $version = PolicyVersion::query()
            ->where('policy_id', $policy->getKey())
            ->where('state', PolicyVersionState::Published)
            ->where('effective_at', '<=', now())
            ->first();

        abort_if($version === null, 404);

        return Inertia::render('policies/Show', [
            'policy' => [
                'name' => $policy->name,
                'slug' => $policy->slug,
                'version' => $version->version,
                'effectiveAt' => $version->effective_at?->toDateString(),
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
     */
    public function required(Request $request, PolicyMarkdown $markdown, OutstandingConsents $outstanding): Response|RedirectResponse
    {
        $subjectId = (string) $request->user()?->getAuthIdentifier();

        $versions = $outstanding->forSubject($subjectId);

        if ($versions->isEmpty()) {
            return redirect()->intended('/');
        }

        return Inertia::render('policies/Required', [
            'versions' => $versions->map(fn (PolicyVersion $version): array => [
                'id' => $version->getKey(),
                'name' => $version->policy->name,
                'slug' => $version->policy->slug,
                'version' => $version->version,
                'effectiveAt' => $version->effective_at?->toDateString(),
                'html' => $markdown->toHtml($version->content),
            ])->all(),
        ]);
    }
}
