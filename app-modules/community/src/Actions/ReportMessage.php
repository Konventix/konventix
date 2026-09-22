<?php

declare(strict_types=1);

namespace Konventix\Community\Actions;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Konventix\Community\Data\ReportData;
use Konventix\Community\Enums\ReportState;
use Konventix\Community\Events\ShoutboxMessageReported;
use Konventix\Community\Models\Message;
use Konventix\Community\Models\MessageReport;

/**
 * Puts one line in the desk's queue (spec §4.4, §6, D-COM-01; ADR-0015).
 *
 * **Post-hoc moderation with participant reporting** is the whole approach
 * (D-COM-01): no pre-publication queue, because nobody shouts into one, and
 * no filter lists. This table is also the seam a later auto-moderation API
 * feeds into (D-COM-02) — a classifier writes reports like any other
 * reporter and the desk's workflow does not change, which is why the answer
 * to moderation here is a queue and not a filter.
 *
 * Any participant may report a line **once**: `unique(message_id,
 * reporter_user_id)` says so at the database, and a second attempt by the
 * same reporter returns the report they already filed rather than raising
 * a constraint violation at somebody who pressed a button twice. Nothing is
 * dispatched on that second call — one act, one event.
 *
 * **A removed line can still be reported, and a report on it still
 * resolves.** That is what the tombstone of spec §4.2 is for: a report
 * naming a message the desk already removed is not a dangling row, it is
 * the ordinary race between a reporter and a moderator.
 */
final class ReportMessage
{
    public function handle(Message $message, string $reporterUserId, ReportData $data): MessageReport
    {
        $note = $data->note === null ? null : (trim($data->note) === '' ? null : trim($data->note));
        $now = CarbonImmutable::now()->utc();

        /** @var array{report: MessageReport, created: bool} $outcome */
        $outcome = DB::transaction(function () use ($message, $reporterUserId, $note): array {
            $existing = MessageReport::withoutBrandScope()
                // Explicit brand rather than the ambient scope: a report
                // may be filed from a path with no resolved brand, where
                // `BrandScope` hardens to zero rows (ADR-0029) — reading
                // nothing here would mean a second insert straight into the
                // unique index.
                ->where('brand_id', $message->brand_id)
                ->where('message_id', $message->id)
                ->where('reporter_user_id', $reporterUserId)
                ->first();

            if ($existing instanceof MessageReport) {
                return ['report' => $existing, 'created' => false];
            }

            $report = new MessageReport([
                'message_id' => $message->id,
                'reporter_user_id' => $reporterUserId,
                'note' => $note,
                'state' => ReportState::Open,
            ]);

            // The message's brand, never ambient context (ADR-0010).
            $report->forceFill(['brand_id' => $message->brand_id]);
            $report->save();

            return ['report' => $report, 'created' => true];
        });

        if ($outcome['created']) {
            // After the commit — see `PostMessage::handle()` for why.
            // Neither the body nor `$note` travels: both are user-generated
            // text, and spec §9 keeps it off every payload.
            event(new ShoutboxMessageReported(
                reportId: $outcome['report']->id,
                messageId: $message->id,
                roomId: $message->room_id,
                brandId: $message->brand_id,
                reporterUserId: $reporterUserId,
                reportedAt: $now->toIso8601String(),
            ));
        }

        return $outcome['report'];
    }
}
