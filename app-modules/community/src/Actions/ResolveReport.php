<?php

declare(strict_types=1);

namespace Konventix\Community\Actions;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Konventix\Community\Enums\ReportState;
use Konventix\Community\Models\MessageReport;

/**
 * Closes one entry in the desk's queue (spec §4.4, §6; ADR-0015).
 *
 * Two outcomes and no third: `Resolved` — the desk acted — or `Dismissed`
 * — the desk looked and there was nothing to do. Both are terminal, and
 * `Open` is refused here rather than silently reopening a report, because
 * "un-resolve" is not a moderation verb D-COM-08 gives anyone.
 *
 * **Terminal is enforced, not only stated.** A report that is already
 * resolved or dismissed is returned untouched, the way `RemoveMessage` and
 * `LockRoom` treat an act that already happened. Two moderators working
 * the queue from tabs loaded minutes apart is the ordinary way a second
 * call arrives, and rewriting the triple would erase who actually worked
 * the item with nothing left anywhere to say it happened.
 *
 * `state`, `resolved_by_user_id` and `resolved_at` are written together:
 * no constraint ties them, and a resolved report with no resolver is a row
 * the console cannot attribute.
 *
 * **It resolves against a tombstone as happily as against a live line.**
 * Removing a message keeps its row (spec §4.2) precisely so that the
 * reports naming it stay answerable, so nothing here asks whether the
 * message survived.
 *
 * Nothing is dispatched. Spec §9's three events are what Community
 * publishes; the desk closing its own queue item is internal.
 */
final class ResolveReport
{
    public function handle(MessageReport $report, string $byUserId, ReportState $state): MessageReport
    {
        if ($state === ReportState::Open) {
            throw new InvalidArgumentException('A report is resolved or dismissed; reopening one is not a moderation verb.');
        }

        if ($report->state !== ReportState::Open) {
            return $report;
        }

        return DB::transaction(function () use ($report, $byUserId, $state): MessageReport {
            $report->forceFill([
                'state' => $state,
                'resolved_by_user_id' => $byUserId,
                'resolved_at' => CarbonImmutable::now()->utc(),
            ])->save();

            return $report;
        });
    }
}
