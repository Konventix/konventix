<?php

declare(strict_types=1);

namespace Konventix\Compliance\Http\Controllers;

use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Konventix\Compliance\Enums\DataSubjectRequestState;
use Konventix\Compliance\Enums\DataSubjectRequestType;
use Konventix\Compliance\Enums\PolicyVersionState;
use Konventix\Compliance\Models\Consent;
use Konventix\Compliance\Models\DataSubjectRequest;
use Konventix\Compliance\Models\PolicyVersion;
use Konventix\Compliance\Support\OutstandingConsents;
use Konventix\Platform\Models\Scopes\BrandScope;

/**
 * The account's privacy page: what this person has agreed to, when, and what
 * is still outstanding — the transparency half of Art. 7(1) GDPR, where the
 * controller must be able to *demonstrate* consent, and the data subject is
 * entitled to see the same record.
 *
 * Lives with the other account settings pages (`settings/Privacy`) the way
 * Messaging's notification preferences do: the shell is Identity's, the rows
 * are this module's (ADR-0003 — no module wiring in a central place).
 *
 * It is also where a person exercises Art. 15 and Art. 17: asking for their
 * data, or for its deletion, and downloading what came back while the
 * archive lasts.
 */
final class PrivacyController
{
    public function edit(Request $request, OutstandingConsents $outstanding): Response
    {
        $subjectId = (string) $request->user()->getAuthIdentifier();

        // The consent rows stay brand-scoped — a consent is always recorded
        // in a brand context — but the version and policy behind one may be
        // operator-wide (GitLab #76), and a scoped eager load would hand this
        // page a null version to name (.ai/rules/app-modules-src.md).
        $consents = Consent::query()
            ->where('subject_id', $subjectId)
            ->with([
                'policyVersion' => fn ($query) => $query->withoutGlobalScope(BrandScope::class)
                    ->with(['policy' => fn ($inner) => $inner->withoutGlobalScope(BrandScope::class)]),
            ])
            ->orderByDesc('granted_at')
            ->get();

        // Consents with a ConsentWithdrawal request already awaiting the
        // operator's decision (ADR-0050 §7/§8) — the page shows "pending"
        // instead of a button that would just open a second one.
        $pendingWithdrawalConsentIds = DataSubjectRequest::query()
            ->where('subject_id', $subjectId)
            ->where('type', DataSubjectRequestType::ConsentWithdrawal)
            ->whereIn('state', [DataSubjectRequestState::Received, DataSubjectRequestState::InProgress])
            ->pluck('consent_id')
            ->all();

        return Inertia::render('settings/Privacy', [
            'consents' => $consents->map(fn (Consent $consent): array => [
                'id' => $consent->getKey(),
                'policyName' => $consent->policyVersion->policy->name,
                'policySlug' => $consent->policyVersion->policy->slug,
                'version' => $consent->policyVersion->version,
                // ISO 8601 with the offset, not `toDateTimeString()`: an offsetless
                // string is parsed by the browser as *local* time, and the viewer's
                // zone is then applied a second time on top of digits that were
                // already UTC (#243 review finding 3).
                'grantedAt' => $consent->granted_at->toIso8601String(),
                'withdrawnAt' => $consent->withdrawn_at?->toIso8601String(),
                'captureContext' => $consent->capture_context,
                // Withdrawing a required policy's consent re-raises the gate;
                // the page says so rather than surprising the person with it.
                'required' => $consent->policyVersion->requires_consent
                    && $consent->policyVersion->state === PolicyVersionState::Published,
                // Art. 7(3) only applies where the basis really is consent
                // (ADR-0050 §7) — everywhere else the button files a request.
                'selfServiceWithdrawal' => $consent->policyVersion->lawful_basis->allowsSelfServiceWithdrawal(),
                'withdrawalPending' => in_array($consent->getKey(), $pendingWithdrawalConsentIds, true),
            ])->all(),
            'outstanding' => $outstanding->forSubject($subjectId)
                ->map(fn (PolicyVersion $version): array => [
                    'id' => $version->getKey(),
                    'name' => $version->policy->name,
                    'slug' => $version->policy->slug,
                    'version' => $version->version,
                ])->all(),
            // ConsentWithdrawal requests are excluded here: they already have
            // a home next to their consent above ("Withdrawal pending"),
            // and listing one here too would both duplicate it and mislabel
            // it — this list's type ternary in Privacy.vue only knows
            // export/deletion (ADR-0050 §7/§8).
            'requests' => DataSubjectRequest::query()
                ->where('subject_id', $subjectId)
                ->where('type', '!=', DataSubjectRequestType::ConsentWithdrawal)
                ->orderByDesc('created_at')
                ->get()
                ->map(fn (DataSubjectRequest $subjectRequest): array => [
                    'id' => $subjectRequest->getKey(),
                    'type' => $subjectRequest->type->value,
                    'state' => $subjectRequest->state->value,
                    'openedAt' => $subjectRequest->created_at?->toIso8601String(),
                    'deadlineAt' => $subjectRequest->deadline_at->toDateString(),
                    // Whether *this* person can still fetch the archive, not
                    // whether one was ever written: an expired archive is
                    // deleted, and offering the button anyway would 404.
                    'downloadable' => $subjectRequest->hasDownloadableArchive(),
                    'archiveExpiresAt' => $subjectRequest->archive_expires_at?->toDateString(),
                ])->all(),
            'openTypes' => DataSubjectRequest::query()
                ->where('subject_id', $subjectId)
                ->whereIn('state', [DataSubjectRequestState::Received, DataSubjectRequestState::InProgress])
                ->pluck('type')
                ->map(fn (DataSubjectRequestType $type): string => $type->value)
                ->values()
                ->all(),
        ]);
    }
}
