<?php

declare(strict_types=1);

namespace Konventix\Compliance\Metrics;

use Konventix\Compliance\Enums\LawfulBasis;
use Konventix\Compliance\Enums\PolicyVersionState;
use Konventix\Compliance\Models\Consent;
use Konventix\Compliance\Models\Policy;
use Konventix\Compliance\Models\PolicyVersion;
use Konventix\Platform\Models\Brand;
use Spatie\Prometheus\Collectors\Collector;
use Spatie\Prometheus\Facades\Prometheus;

/**
 * Compliance's scrape-time gauge (ADR-0059, ADR-0066 spec §10): how many
 * people still owe agreement to a contract text.
 *
 * The closure runs when `/metrics` is rendered, outside any brand context,
 * so every query drops the brand scope and groups by brand itself — and an
 * operator-wide policy carries no `brand_id` at all, which the scoped query
 * could never see anyway (ADR-0010, #76).
 *
 * **Every brand gets a row, zero included.** A gauge closure that returned
 * rows only for brands with something outstanding would leave the last
 * non-zero value standing after the condition passed — the failure
 * `CommerceGauges` documents at length, where `payments_pending{brand}`
 * outlived its order by two days.
 *
 * "Outstanding" is `OutstandingConsents::subjectHasOutstandingContract()`'s
 * definition, asked for every subject at once rather than for one: the
 * published, effective, consent-requiring version of a policy the brand
 * reads, whose basis is the contract, for which the subject holds no consent
 * that has not been withdrawn. A subject the brand has never heard of cannot
 * owe anything, so the population is the brand's own consent rows — the only
 * subjects this module knows about at all.
 */
final class ComplianceGauges implements Collector
{
    public function register(): void
    {
        Prometheus::addGauge('Outstanding contract subjects')
            ->name('outstanding_contract_subjects')
            ->label('brand')
            ->helpText('Subjects of the brand who still owe agreement to a published contract text.')
            ->value(fn (): array => $this->outstandingPerBrand());
    }

    /**
     * @return list<array{float, list<string>}>
     */
    private function outstandingPerBrand(): array
    {
        $rows = [];

        foreach (Brand::query()->get(['id', 'slug']) as $brand) {
            $brandId = (string) $brand->getKey();
            $required = $this->requiredContractVersionIds($brandId);

            $outstanding = $required === []
                ? 0
                : $this->subjectsMissingAnyOf($brandId, $required);

            $rows[] = [(float) $outstanding, [(string) $brand->slug]];
        }

        return $rows;
    }

    /**
     * @return list<string>
     */
    private function requiredContractVersionIds(string $brandId): array
    {
        $policyIds = Policy::resolvedFor($brandId)->modelKeys();

        if ($policyIds === []) {
            return [];
        }

        return array_values(array_map(strval(...), PolicyVersion::withoutBrandScope()
            ->whereIn('policy_id', $policyIds)
            ->where('state', PolicyVersionState::Published)
            ->where('requires_consent', true)
            ->where('lawful_basis', LawfulBasis::Contract)
            ->where('effective_at', '<=', now())
            ->pluck('id')
            ->all()));
    }

    /**
     * How many of this brand's subjects hold a live consent for fewer than
     * all of the required versions.
     *
     * Counted in SQL rather than by loading subjects: the population is
     * every subject the brand has ever recorded a consent for, and on a
     * busy instance that is the whole customer base.
     *
     * @param  list<string>  $requiredVersionIds
     */
    private function subjectsMissingAnyOf(string $brandId, array $requiredVersionIds): int
    {
        $subjects = Consent::withoutBrandScope()
            ->where('brand_id', $brandId)
            ->distinct()
            ->pluck('subject_id');

        if ($subjects->isEmpty()) {
            return 0;
        }

        $satisfied = Consent::withoutBrandScope()
            ->where('brand_id', $brandId)
            ->whereNull('withdrawn_at')
            ->whereIn('policy_version_id', $requiredVersionIds)
            ->selectRaw('subject_id, count(distinct policy_version_id) as held')
            ->groupBy('subject_id')
            ->pluck('held', 'subject_id');

        $needed = count($requiredVersionIds);
        $outstanding = 0;

        foreach ($subjects as $subjectId) {
            if ((int) ($satisfied[$subjectId] ?? 0) < $needed) {
                $outstanding++;
            }
        }

        return $outstanding;
    }
}
