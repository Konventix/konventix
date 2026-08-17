<?php

declare(strict_types=1);

namespace Konventix\Compliance\Http\Controllers;

use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Konventix\Compliance\Enums\PolicyVersionState;
use Konventix\Compliance\Models\Consent;
use Konventix\Compliance\Models\PolicyVersion;
use Konventix\Compliance\Support\OutstandingConsents;

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
 * Export and deletion requests get their own console on this page later
 * (slice 14's DSR half); this commit is consent only.
 */
final class PrivacyController
{
    public function edit(Request $request, OutstandingConsents $outstanding): Response
    {
        $subjectId = (string) $request->user()->getAuthIdentifier();

        $consents = Consent::query()
            ->where('subject_id', $subjectId)
            ->with('policyVersion.policy')
            ->orderByDesc('granted_at')
            ->get();

        return Inertia::render('settings/Privacy', [
            'consents' => $consents->map(fn (Consent $consent): array => [
                'id' => $consent->getKey(),
                'policyName' => $consent->policyVersion->policy->name,
                'policySlug' => $consent->policyVersion->policy->slug,
                'version' => $consent->policyVersion->version,
                'grantedAt' => $consent->granted_at->toDateTimeString(),
                'withdrawnAt' => $consent->withdrawn_at?->toDateTimeString(),
                'captureContext' => $consent->capture_context,
                // Withdrawing a required policy's consent re-raises the gate;
                // the page says so rather than surprising the person with it.
                'required' => $consent->policyVersion->requires_consent
                    && $consent->policyVersion->state === PolicyVersionState::Published,
            ])->all(),
            'outstanding' => $outstanding->forSubject($subjectId)
                ->map(fn (PolicyVersion $version): array => [
                    'id' => $version->getKey(),
                    'name' => $version->policy->name,
                    'slug' => $version->policy->slug,
                    'version' => $version->version,
                ])->all(),
        ]);
    }
}
