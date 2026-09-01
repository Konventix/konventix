<?php

declare(strict_types=1);

namespace Konventix\Compliance\Actions;

use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Konventix\Compliance\Data\PolicyVersionData;
use Konventix\Compliance\Enums\PolicyVersionState;
use Konventix\Compliance\Models\Policy;
use Konventix\Compliance\Models\PolicyVersion;

/**
 * Drafts a new version of a policy.
 *
 * Always a draft, never published: publication emits `PolicyPublished` and —
 * for a version that requires consent — invalidates everyone's existing
 * agreement, so it stays one deliberate act (`PublishPolicyVersion`) rather
 * than a side effect of saving text.
 *
 * The version label is unique per policy at the database level; this refuses
 * a duplicate with a readable message instead of a constraint violation.
 */
final class DraftPolicyVersion
{
    public function __invoke(Policy $policy, PolicyVersionData $data): PolicyVersion
    {
        return DB::transaction(function () use ($policy, $data): PolicyVersion {
            $exists = PolicyVersion::withoutBrandScope()
                ->where('policy_id', $policy->getKey())
                ->where('version', $data->version)
                ->exists();

            if ($exists) {
                throw new InvalidArgumentException(
                    "Version [{$data->version}] already exists for this policy.",
                );
            }

            $version = new PolicyVersion([
                'version' => $data->version,
                'content' => $data->content,
                'requires_consent' => $data->requiresConsent,
                'lawful_basis' => $data->lawfulBasis,
                'state' => PolicyVersionState::Draft,
                'effective_at' => $data->effectiveAt,
            ]);

            $version->forceFill([
                'brand_id' => $policy->brand_id,
                'policy_id' => $policy->getKey(),
            ])->save();

            return $version;
        });
    }
}
