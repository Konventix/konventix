<?php

declare(strict_types=1);

namespace Konventix\Admission\Listeners;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Konventix\Admission\Actions\ProjectEventPresence;
use Konventix\Admission\Enums\HubAccessEndReason;
use Konventix\Admission\Events\AdmissionRevoked;

/**
 * The admission that let them in is gone; so is the hub (design §4.2) —
 * unless the same person holds another admission for the event that still
 * stands, in which case `ProjectEventPresence::endForAdmission()` moves
 * the row over to it and access continues (ruling, 2026-09-22). A holder
 * with no valid admission left is re-admitted only by a later accepted
 * scan on a new one. Registered in
 * `AdmissionServiceProvider::registerListeners()` (D-HUB-01).
 *
 * **Queued, after commit**, like `ProjectPresenceOnAdmissionCheckedIn`:
 * `AdmissionRevoked` fires inside `RevokeAdmissionsForRegistration`'s
 * transaction, and `$afterCommit` both keeps the hub off the revocation's
 * critical path and guarantees the survivor lookup sees the revoked state
 * committed rather than a half-finished batch. Constructor injection is the
 * action only (`.ai/rules/listeners.md`).
 */
final class EndHubAccessOnAdmissionRevoked implements ShouldQueue
{
    use InteractsWithQueue;

    public bool $afterCommit = true;

    public int $tries = 3;

    public function __construct(private readonly ProjectEventPresence $presence) {}

    public function handle(AdmissionRevoked $event): void
    {
        $this->presence->endForAdmission($event->brandId, $event->admissionId, HubAccessEndReason::Revoked, CarbonImmutable::parse($event->revokedAt));
    }
}
