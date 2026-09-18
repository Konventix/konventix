<?php

declare(strict_types=1);

namespace Konventix\Admission\Listeners\Metrics;

use Konventix\Admission\Events\AdmissionHolderAssigned;
use Konventix\Admission\Events\AdmissionHolderUnassigned;
use Konventix\Admission\Events\ClaimLinkIssued;
use Konventix\Admission\Events\ClaimLinkRevoked;
use Konventix\Admission\Events\ClaimLinkSent;
use Konventix\Platform\Brands\BrandSlugs;
use Konventix\Platform\Observability\Metrics;

/**
 * `admission_holder_changes_total` and `admission_claim_invites_total`
 * (#292 §9, ADR-0059): how tickets change hands and how invitations fare.
 * Bounded label sets — brand slug and an outcome word — never an id.
 * `Metrics` resolved in handle(), as every recorder here does
 * (`.ai/rules/listeners.md`).
 */
final class RecordHolderMetrics
{
    public function handleAssigned(AdmissionHolderAssigned $event): void
    {
        $this->holderChange($event->brandId, $event->viaClaimLink ? 'claimed' : 'assigned');
    }

    public function handleUnassigned(AdmissionHolderUnassigned $event): void
    {
        $this->holderChange($event->brandId, 'unassigned');
    }

    public function handleIssued(ClaimLinkIssued $event): void
    {
        $this->invite($event->brandId, 'issued');
    }

    public function handleSent(ClaimLinkSent $event): void
    {
        $this->invite($event->brandId, $event->sent ? 'sent' : 'send_failed');
    }

    public function handleRevoked(ClaimLinkRevoked $event): void
    {
        $this->invite($event->brandId, 'revoked');
    }

    private function holderChange(string $brandId, string $outcome): void
    {
        app(Metrics::class)->increment('admission_holder_changes_total', [
            'brand' => BrandSlugs::of($brandId),
            'outcome' => $outcome,
        ]);
    }

    private function invite(string $brandId, string $outcome): void
    {
        app(Metrics::class)->increment('admission_claim_invites_total', [
            'brand' => BrandSlugs::of($brandId),
            'outcome' => $outcome,
        ]);
    }
}
