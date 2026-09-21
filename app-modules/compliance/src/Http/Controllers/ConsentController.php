<?php

declare(strict_types=1);

namespace Konventix\Compliance\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Konventix\Compliance\Actions\GrantConsent;
use Konventix\Compliance\Actions\WithdrawConsent;
use Konventix\Compliance\Enums\PolicyVersionState;
use Konventix\Compliance\Http\Middleware\EnsureContractConsent;
use Konventix\Compliance\Models\Consent;
use Konventix\Compliance\Models\DataSubjectRequest;
use Konventix\Compliance\Models\Policy;
use Konventix\Compliance\Models\PolicyVersion;
use Konventix\Platform\Brands\BrandContext;

/**
 * Capturing and withdrawing a subject's agreement, on the brand's own
 * domain.
 *
 * The subject is always the authenticated user: these routes carry `auth`,
 * and consent is never recorded on someone else's behalf from a form. (The
 * account-less subject identity Consent's column tolerates arrives with
 * guest registration; it will not arrive through this controller.)
 *
 * Only a currently published version of a policy *this brand reads* may be
 * agreed to. Accepting a draft or a superseded text would produce a consent
 * record pointing at something nobody is bound by — worse than no record,
 * because it looks like one; accepting another brand's own text would do
 * the same with wording this brand never served.
 */
final class ConsentController
{
    public function store(Request $request, GrantConsent $grantConsent): RedirectResponse
    {
        $validated = $request->validate([
            'versionIds' => ['required', 'array', 'min:1'],
            'versionIds.*' => ['ulid'],
            'captureContext' => ['nullable', 'string', 'max:100'],
        ]);

        $subjectId = (string) $request->user()->getAuthIdentifier();
        $context = $validated['captureContext'] ?? 'consent_form';

        // Escapes BrandScope on purpose (ADR-0010): a version of an
        // operator-wide policy carries no `brand_id` (GitLab #76), so the
        // scoped query would silently drop exactly the texts every brand
        // inherits. Narrowed back to this brand's own catalogue by policy —
        // `Policy::resolvedFor()` is the same resolution `OutstandingConsents`
        // offers from (the brand's own row per slug, the operator's
        // everywhere else), so escaping the scope cannot turn into accepting
        // a neighbouring brand's own published version off the form.
        //
        // `BrandContext` is read at call time, never constructor-injected
        // (.ai/rules/providers.md). No brand resolved means no catalogue and
        // nothing to record.
        $brandId = app(BrandContext::class)->id();
        $policyIds = $brandId === null ? [] : Policy::resolvedFor($brandId)->modelKeys();

        // This narrows the ids the form posted back to those still published
        // and effective.
        $versions = PolicyVersion::withoutBrandScope()
            ->whereIn('id', $validated['versionIds'])
            ->whereIn('policy_id', $policyIds)
            ->where('state', PolicyVersionState::Published)
            ->where('effective_at', '<=', now())
            ->get();

        foreach ($versions as $version) {
            $grantConsent($subjectId, $version, $context);
        }

        // The act the gate was raised on is answered; its label has no
        // business heading an interstitial somebody reaches later for an
        // unrelated reason (ADR-0066 D-CPL-15).
        $request->session()->forget(EnsureContractConsent::ACT_SESSION_KEY);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Thank you — your agreement is recorded.')]);

        // Back to wherever the gate interrupted, when it was the gate that
        // sent them here; the brand's front page otherwise.
        return redirect()->intended('/');
    }

    /**
     * Withdrawal never deletes: the record that consent was once given, and
     * when it stopped being valid, is itself the audit-relevant fact
     * (`WithdrawConsent`). Withdrawing a *required* policy's consent puts the
     * gate back in front of the person on their next gated request, which the
     * privacy page says out loud before they press the button.
     *
     * Where the policy's basis is not consent, `WithdrawConsent` opens a
     * DataSubjectRequest instead of withdrawing (ADR-0050 §7) — the toast
     * says so rather than claiming a withdrawal that has not happened yet.
     */
    public function destroy(Request $request, string $consent, WithdrawConsent $withdrawConsent): RedirectResponse
    {
        /** @var Consent|null $model */
        $model = Consent::query()->find($consent);

        abort_if($model === null, 404);

        // Brand scope already limits this to the current brand; the subject
        // check is what stops one account withdrawing another's consent.
        abort_unless($model->subject_id === (string) $request->user()->getAuthIdentifier(), 403);

        if ($model->isWithdrawn()) {
            Inertia::flash('toast', ['type' => 'success', 'message' => __('Consent withdrawn.')]);

            return back();
        }

        $result = $withdrawConsent($model);

        Inertia::flash('toast', $result instanceof DataSubjectRequest
            ? ['type' => 'info', 'message' => __('Withdrawal requested — the operator will review it.')]
            : ['type' => 'success', 'message' => __('Consent withdrawn.')]);

        return back();
    }
}
