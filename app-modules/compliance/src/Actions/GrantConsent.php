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
 *
 * Granting twice returns the consent already on record instead of writing a
 * second row: a double-submitted consent form, or a page reloaded after the
 * POST, is one agreement, and duplicate rows would make "when did they
 * agree" ambiguous and notify listeners twice. A *re-grant after withdrawal*
 * is a different fact and does create a new row — the withdrawn one stays as
 * the record of the period in between.
 *
 * Since ADR-0066 the caller may also say *why* the text was asked for
 * (D-CPL-09): `policyBindingId` names the binding that made it applicable and
 * `bindingContext` snapshots the scalars the resolution ran on. Both are
 * evidence, not a read model — read back on one row to answer "why was this
 * person asked, and for which purchase", never queried to find
 * registrations. Both default to null, which is what an unbound resolution
 * passes and what every row written before that migration carries.
 *
 * The idempotent branch does **not** rewrite them: the row already records
 * the moment somebody agreed, and a second click from another page is not a
 * second agreement that may restate the reason for the first.
 */
final class GrantConsent
{
    /**
     * @param  array<string, mixed>|null  $bindingContext
     */
    public function __invoke(
        string $subjectId,
        PolicyVersion $policyVersion,
        string $captureContext,
        ?CarbonInterface $grantedAt = null,
        ?string $policyBindingId = null,
        ?array $bindingContext = null,
    ): Consent {
        return DB::transaction(function () use ($subjectId, $policyVersion, $captureContext, $grantedAt, $policyBindingId, $bindingContext): Consent {
            $grantedAt ??= now();

            /** @var Consent|null $existing */
            $existing = Consent::query()
                ->where('subject_id', $subjectId)
                ->where('policy_version_id', $policyVersion->id)
                ->whereNull('withdrawn_at')
                ->first();

            if ($existing !== null) {
                return $existing;
            }

            $consent = Consent::create([
                'subject_id' => $subjectId,
                'policy_version_id' => $policyVersion->id,
                'capture_context' => $captureContext,
                'granted_at' => $grantedAt,
                'policy_binding_id' => $policyBindingId,
                'binding_context' => $bindingContext,
            ]);

            event(new ConsentGranted(
                consentId: $consent->id,
                subjectId: $subjectId,
                policyId: $policyVersion->policy_id,
                versionId: $policyVersion->id,
                brandId: $consent->brand_id,
                grantedAt: $grantedAt->toIso8601String(),
                captureContext: $captureContext,
            ));

            return $consent;
        });
    }
}
