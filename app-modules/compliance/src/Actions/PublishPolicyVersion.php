<?php

declare(strict_types=1);

namespace Konventix\Compliance\Actions;

use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Konventix\Compliance\Enums\PolicyVersionState;
use Konventix\Compliance\Events\PolicyPublished;
use Konventix\Compliance\Models\Policy;
use Konventix\Compliance\Models\PolicyVersion;

/**
 * Publishes a PolicyVersion and supersedes whichever version of the same
 * policy was previously published — the mechanism that guarantees only one
 * published version is ever effective per policy (TODO.md "Next").
 *
 * `effectiveAt` defaults to the version's own `effective_at` if it was set
 * ahead of publication, falling back to now() so a version can never publish
 * without an effective date.
 *
 * Every read here escapes `BrandScope` explicitly: publishing happens from
 * the operator console, where the scope hardens to zero rows (ADR-0029). A
 * scoped supersede-update would silently match nothing and leave two
 * published versions of the same policy standing, and a scoped relation load
 * would read a null policy (.ai/rules).
 */
final class PublishPolicyVersion
{
    public function __invoke(PolicyVersion $version, ?CarbonInterface $effectiveAt = null): PolicyVersion
    {
        return DB::transaction(function () use ($version, $effectiveAt): PolicyVersion {
            PolicyVersion::withoutBrandScope()
                ->where('policy_id', $version->policy_id)
                ->where('id', '!=', $version->id)
                ->where('state', PolicyVersionState::Published)
                ->update(['state' => PolicyVersionState::Superseded]);

            $version->forceFill([
                'state' => PolicyVersionState::Published,
                'effective_at' => $effectiveAt ?? $version->effective_at ?? now(),
                'published_at' => now(),
            ])->save();

            $policy = Policy::withoutBrandScope()->findOrFail($version->policy_id);

            event(new PolicyPublished(
                policyId: $version->policy_id,
                versionId: $version->id,
                brandId: $version->brand_id,
                slug: $policy->slug,
                effectiveAt: $version->effective_at->toIso8601String(),
                requiresConsent: $version->requires_consent,
            ));

            return $version;
        });
    }
}
