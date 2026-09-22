<?php

declare(strict_types=1);

namespace Konventix\Community\Actions;

use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Konventix\Community\Data\ReportData;
use Konventix\Community\Enums\ReportState;
use Konventix\Community\Events\ShoutboxMessageReported;
use Konventix\Community\Exceptions\ShoutboxWriteException;
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
 * **Both halves of that are enforced.** The read below answers the ordinary
 * double click; a genuinely simultaneous pair of reports from one person
 * both read nothing and the loser reaches the unique index, so the
 * violation is caught and answered with the report that won. A race
 * between a person and themselves must not be a 500.
 *
 * **A removed line can still be reported, and a report on it still
 * resolves.** That is what the tombstone of spec §4.2 is for: a report
 * naming a message the desk already removed is not a dangling row, it is
 * the ordinary race between a reporter and a moderator.
 */
final class ReportMessage
{
    /**
     * @throws ShoutboxWriteException when the note is longer than its column
     */
    public function handle(Message $message, string $reporterUserId, ReportData $data): MessageReport
    {
        $note = $this->guardNote($data->note);
        $now = CarbonImmutable::now()->utc();

        try {
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
        } catch (UniqueConstraintViolationException) {
            // The read found nothing and the insert still met
            // `unique(message_id, reporter_user_id)`: another request from
            // this same person committed in between. That is one act, so
            // the answer is the report already on file — the same answer
            // the double click gets — and no second event.
            return $this->reportOnFile($message, $reporterUserId);
        }

        if ($outcome['created']) {
            // After the commit, and through `DB::afterCommit()` so that it
            // is still after the commit when a caller wraps this Action in
            // a transaction of its own — see `PostMessage::handle()`.
            // Neither the body nor `$note` travels: both are user-generated
            // text, and spec §9 keeps it off every payload.
            $reported = new ShoutboxMessageReported(
                reportId: $outcome['report']->id,
                messageId: $message->id,
                roomId: $message->room_id,
                brandId: $message->brand_id,
                reporterUserId: $reporterUserId,
                reportedAt: $now->toIso8601String(),
            );

            DB::afterCommit(static fn () => event($reported));
        }

        return $outcome['report'];
    }

    /**
     * Trimmed, with an empty note normalised to null — an empty sentence in
     * the queue is worse than no sentence — and capped at the column's own
     * `varchar(255)`. The cap is a participant-facing refusal rather than an
     * `InvalidArgumentException`, because a reporter reaches this field
     * directly: a client that ignores the form's `maxlength` must be told
     * what is wrong in German (ADR-0001), not handed a 500 from
     * `SQLSTATE[22001]`.
     */
    private function guardNote(?string $note): ?string
    {
        $trimmed = trim($note ?? '');

        if ($trimmed === '') {
            return null;
        }

        if (mb_strlen($trimmed) > ReportData::MAX_NOTE_LENGTH) {
            throw ShoutboxWriteException::noteTooLong(ReportData::MAX_NOTE_LENGTH);
        }

        return $trimmed;
    }

    /**
     * The row the unique index collided with. Keyed on `(message_id,
     * reporter_user_id)` and nothing else, because that pair *is* the index
     * that just refused the insert — a brand filter here could miss the very
     * row whose existence is the reason this method was called.
     */
    private function reportOnFile(Message $message, string $reporterUserId): MessageReport
    {
        return MessageReport::withoutBrandScope()
            ->where('message_id', $message->id)
            ->where('reporter_user_id', $reporterUserId)
            ->firstOrFail();
    }
}
