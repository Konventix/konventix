<?php

declare(strict_types=1);

namespace Konventix\Compliance\Actions;

use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Konventix\Compliance\Events\ConsentGranted;
use Konventix\Compliance\Models\Consent;
use Konventix\Compliance\Models\PolicyVersion;

/**
 * Records a subject's agreement to a PolicyVersion and emits ConsentGranted.
 *
 * `subjectId` is not validated against any table on purpose — Compliance
 * never queries a foreign table (ADR-0004), and the subject may not have an
 * account at all (see Consent's docblock / decision-log Round 5 D-REG-08).
 */
final class GrantConsent
{
    public function __invoke(
        string $subjectId,
        PolicyVersion $policyVersion,
        string $captureContext,
        ?CarbonInterface $grantedAt = null,
    ): Consent {
        return DB::transaction(function () use ($subjectId, $policyVersion, $captureContext, $grantedAt): Consent {
            $grantedAt ??= now();

            $consent = Consent::create([
                'subject_id' => $subjectId,
                'policy_version_id' => $policyVersion->id,
                'capture_context' => $captureContext,
                'granted_at' => $grantedAt,
            ]);

            event(new ConsentGranted(
                consentId: $consent->id,
                subjectId: $subjectId,
                policyId: $policyVersion->policy_id,
                versionId: $policyVersion->id,
                brandId: $consent->brand_id,
                grantedAt: $grantedAt->toIso8601String(),
            ));

            return $consent;
        });
    }
}
