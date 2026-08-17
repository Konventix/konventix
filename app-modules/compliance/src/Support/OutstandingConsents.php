<?php

declare(strict_types=1);

namespace Konventix\Compliance\Support;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Konventix\Compliance\Enums\PolicyVersionState;
use Konventix\Compliance\Models\Consent;
use Konventix\Compliance\Models\PolicyVersion;

/**
 * Which policy versions a subject still owes agreement to, for the brand the
 * request is resolved into.
 *
 * One query shared by the three places that must agree on the answer: the
 * `require-consent` middleware that blocks, the page that asks, and the
 * privacy settings page that explains. Two implementations of "outstanding"
 * would eventually disagree, and the failure mode is either a redirect loop
 * or an unenforced legal requirement.
 *
 * Brand scoping is `BelongsToBrand`'s global scope — this only ever runs on
 * a brand host, so nothing here escapes it (ADR-0010).
 *
 * "Outstanding" means: the currently published version of a policy, which
 * requires consent, whose effective date has arrived, and for which the
 * subject holds no consent that has not been withdrawn. A withdrawn consent
 * therefore makes the policy outstanding again — which is the point of a
 * required policy, and why the settings page warns before withdrawing one.
 */
final class OutstandingConsents
{
    /**
     * @return Collection<int, PolicyVersion>
     */
    public function forSubject(string $subjectId): Collection
    {
        return $this->requiredVersions()
            ->whereNotIn('id', $this->consentedVersionIds($subjectId))
            ->with('policy')
            ->orderBy('created_at')
            ->get();
    }

    public function subjectHasOutstanding(string $subjectId): bool
    {
        return $this->requiredVersions()
            ->whereNotIn('id', $this->consentedVersionIds($subjectId))
            ->exists();
    }

    /**
     * @return Builder<PolicyVersion>
     */
    private function requiredVersions()
    {
        return PolicyVersion::query()
            ->where('state', PolicyVersionState::Published)
            ->where('requires_consent', true)
            ->where('effective_at', '<=', now());
    }

    /**
     * @return Builder<Consent>
     */
    private function consentedVersionIds(string $subjectId)
    {
        return Consent::query()
            ->where('subject_id', $subjectId)
            ->whereNull('withdrawn_at')
            ->select('policy_version_id');
    }
}
