<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Str;
use Konventix\Community\Actions\PostMessage;
use Konventix\Community\Console\SweepExpiredRoomsCommand;
use Konventix\Community\Data\PostMessageData;
use Konventix\Community\Enums\ReportState;
use Konventix\Community\Enums\ShoutboxRefusal;
use Konventix\Community\Exceptions\ShoutboxWriteException;
use Konventix\Community\Gdpr\CommunityGdprHandler;
use Konventix\Community\Models\EventPresence;
use Konventix\Community\Models\Message;
use Konventix\Community\Models\MessageReport;
use Konventix\Community\Models\Room;
use Konventix\Community\Models\RoomMute;
use Konventix\Compliance\Enums\DeletionMode;
use Konventix\Compliance\Gdpr\SubjectRef;
use Konventix\Event\Events\EventCancelled;
use Konventix\Event\Events\EventConcluded;

/**
 * Design §7 and §12 items 7-8: the shoutbox's retention clock and its GDPR
 * half.
 *
 * - `EventConcluded` closes the room (`closed_at`, the conclusion instant),
 *   and so does `EventCancelled` (the cancellation instant) — a cancelled
 *   event never concludes; a closed room refuses every post.
 * - `community:sweep-shoutbox` deletes the room's messages, mutes and
 *   reports **30 days** after that instant and keeps the room row as a
 *   tombstone. Time is frozen explicitly around the boundary, and the day
 *   arithmetic is written out where it is used.
 * - GDPR export carries the subject's own lines and reports (plus their
 *   mutes and admission badges); erasure deletes their lines, anonymises
 *   the reports they filed, deletes their badges and mutes, and nulls their
 *   ULID in every moderator column on somebody else's row.
 *
 * Helpers are prefixed `shoutboxRetention*` — Pest helpers are
 * process-global across every module's suite (`.ai/rules/tests.md`).
 */

/**
 * A room holding one line, one report on it and one mute — the three
 * things the sweep deletes.
 *
 * @return array{room: Room, message: Message, report: MessageReport, mute: RoomMute}
 */
function shoutboxRetentionFilledRoom(?CarbonImmutable $closedAt): array
{
    $room = Room::factory()->create(['closed_at' => $closedAt]);
    $message = Message::factory()->for($room)->create();

    return [
        'room' => $room,
        'message' => $message,
        'report' => MessageReport::factory()->for($message)->create(),
        'mute' => RoomMute::factory()->for($room)->create(),
    ];
}

/**
 * @param  array{room: Room, message: Message, report: MessageReport, mute: RoomMute}  $filled
 */
function shoutboxRetentionStillHolds(array $filled): bool
{
    return Message::withoutBrandScope()->whereKey($filled['message']->id)->exists()
        && MessageReport::withoutBrandScope()->whereKey($filled['report']->id)->exists()
        && RoomMute::withoutBrandScope()->whereKey($filled['mute']->id)->exists();
}

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

it('closes the event\'s room on EventConcluded, at the conclusion instant, and the room stops accepting posts', function (): void {
    $brand = $this->withBrand();
    $this->activateModule('community', $brand);

    $eventId = (string) Str::ulid();
    $room = Room::factory()->create(['subject_id' => $eventId]);
    $otherRoom = Room::factory()->create();

    event(new EventConcluded(eventId: $eventId, brandId: $brand->getKey(), concludedAt: '2026-10-18T20:00:00+00:00'));

    // The event's own instant, not the listener's clock — the retention
    // clock starts when the event ended, however late the listener ran.
    expect($room->refresh()->closed_at?->toIso8601String())->toBe('2026-10-18T20:00:00+00:00')
        ->and($otherRoom->refresh()->closed_at)->toBeNull();

    try {
        app(PostMessage::class)->handle($room, (string) Str::ulid(), new PostMessageData('Noch jemand da?'));
        $refusal = null;
    } catch (ShoutboxWriteException $e) {
        $refusal = $e->cause;
    }

    expect($refusal)->toBe(ShoutboxRefusal::RoomClosed)
        ->and(Message::query()->where('room_id', $room->id)->count())->toBe(0);

    // A replay — `event:replay-details --with-state`, or a second
    // conclusion from anywhere — must not move the clock and hand the wall
    // a second 30 days.
    event(new EventConcluded(eventId: $eventId, brandId: $brand->getKey(), concludedAt: '2026-10-25T09:00:00+00:00'));

    expect($room->refresh()->closed_at?->toIso8601String())->toBe('2026-10-18T20:00:00+00:00');
});

it('sweeps a closed room\'s messages, reports and mutes 30 days after it closed, and keeps the room', function (): void {
    $this->withBrand();

    // Room A closed at 2026-10-18 20:00 UTC; room B one day later; room C
    // is still open (its event never concluded).
    $a = shoutboxRetentionFilledRoom(CarbonImmutable::parse('2026-10-18T20:00:00Z'));
    $b = shoutboxRetentionFilledRoom(CarbonImmutable::parse('2026-10-19T20:00:00Z'));
    $c = shoutboxRetentionFilledRoom(null);

    // 30 days after 2026-10-18 20:00 is 2026-11-17 20:00: October has 31
    // days, so 18 Oct + 13 days = 31 Oct, + 17 days = 17 Nov (13 + 17 =
    // 30). One second before that, room A is 29 days, 23:59:59 closed.
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-11-17T19:59:59Z'));

    $this->artisan('community:sweep-shoutbox')->assertSuccessful();

    expect(shoutboxRetentionStillHolds($a))->toBeTrue()
        ->and(shoutboxRetentionStillHolds($b))->toBeTrue()
        ->and(shoutboxRetentionStillHolds($c))->toBeTrue();

    // Exactly 30 days after room A closed: A is due (closed_at <= now - 30
    // days). Room B closed 2026-10-19 20:00, which is 29 days before this
    // instant (13 + 17 - 1), so it is not; room C has no clock at all.
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-11-17T20:00:00Z'));

    $this->artisan('community:sweep-shoutbox')
        ->expectsOutputToContain('Swept 1 shoutbox room(s), 1 message(s) deleted.')
        ->assertSuccessful();

    expect(Message::withoutBrandScope()->where('room_id', $a['room']->id)->exists())->toBeFalse()
        ->and(MessageReport::withoutBrandScope()->whereKey($a['report']->id)->exists())->toBeFalse()
        ->and(RoomMute::withoutBrandScope()->where('room_id', $a['room']->id)->exists())->toBeFalse()
        // The tombstone: a link to the wall opens onto an empty, closed
        // room rather than a 404, and its clock is untouched.
        ->and(Room::withoutBrandScope()->find($a['room']->id)?->closed_at?->toIso8601String())->toBe('2026-10-18T20:00:00+00:00')
        ->and(shoutboxRetentionStillHolds($b))->toBeTrue()
        ->and(shoutboxRetentionStillHolds($c))->toBeTrue();

    // 2026-11-18 20:00 is 30 days after room B closed (19 Oct + 12 = 31
    // Oct, + 18 = 18 Nov). Room A, already swept, is skipped rather than
    // counted a second time.
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-11-18T20:00:00Z'));

    $this->artisan('community:sweep-shoutbox')
        ->expectsOutputToContain('Swept 1 shoutbox room(s), 1 message(s) deleted.')
        ->assertSuccessful();

    expect(Message::withoutBrandScope()->where('room_id', $b['room']->id)->exists())->toBeFalse()
        ->and(Room::withoutBrandScope()->whereKey([$a['room']->id, $b['room']->id, $c['room']->id])->count())->toBe(3)
        ->and(shoutboxRetentionStillHolds($c))->toBeTrue();

    // Nothing left that is due: a further run changes nothing.
    $this->artisan('community:sweep-shoutbox')
        ->expectsOutputToContain('Swept 0 shoutbox room(s), 0 message(s) deleted.')
        ->assertSuccessful();
});

// A running event can be cancelled — and later archived — without ever
// concluding. Its room must close on the cancellation, or the wall's
// messages, reports and mutes would be kept forever.
it('closes the room of a cancelled event at the cancellation instant, and the sweep reaches it 30 days later', function (): void {
    $brand = $this->withBrand();
    $this->activateModule('community', $brand);

    $eventId = (string) Str::ulid();
    $filled = shoutboxRetentionFilledRoom(null);
    $filled['room']->forceFill(['subject_id' => $eventId])->save();

    event(new EventCancelled(eventId: $eventId, brandId: $brand->getKey(), reasonCode: 'venue_lost', cancelledAt: '2026-10-17T15:00:00+00:00'));

    expect($filled['room']->refresh()->closed_at?->toIso8601String())->toBe('2026-10-17T15:00:00+00:00');

    try {
        app(PostMessage::class)->handle($filled['room'], (string) Str::ulid(), new PostMessageData('Findet es noch statt?'));
        $refusal = null;
    } catch (ShoutboxWriteException $e) {
        $refusal = $e->cause;
    }

    expect($refusal)->toBe(ShoutboxRefusal::RoomClosed);

    // 30 days after 2026-10-17 15:00 is 2026-11-16 15:00: 17 Oct + 14 days
    // = 31 Oct, + 16 days = 16 Nov (14 + 16 = 30). One second earlier the
    // room is 29 days, 23:59:59 closed and keeps everything.
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-11-16T14:59:59Z'));

    $this->artisan('community:sweep-shoutbox')
        ->expectsOutputToContain('Swept 0 shoutbox room(s), 0 message(s) deleted.')
        ->assertSuccessful();

    expect(shoutboxRetentionStillHolds($filled))->toBeTrue();

    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-11-16T15:00:00Z'));

    $this->artisan('community:sweep-shoutbox')
        ->expectsOutputToContain('Swept 1 shoutbox room(s), 1 message(s) deleted.')
        ->assertSuccessful();

    expect(Message::withoutBrandScope()->where('room_id', $filled['room']->id)->exists())->toBeFalse()
        ->and(MessageReport::withoutBrandScope()->whereKey($filled['report']->id)->exists())->toBeFalse()
        ->and(RoomMute::withoutBrandScope()->whereKey($filled['mute']->id)->exists())->toBeFalse()
        ->and(Room::withoutBrandScope()->find($filled['room']->id)?->closed_at?->toIso8601String())->toBe('2026-10-17T15:00:00+00:00');
});

it('runs the sweep daily, without overlapping, and names the 30 days the spec gives', function (): void {
    $events = collect(app(Schedule::class)->events())
        ->filter(fn ($e): bool => str_contains($e->command ?? '', 'community:sweep-shoutbox'));

    expect($events)->toHaveCount(1)
        ->and($events->first()->expression)->toBe('0 0 * * *')
        ->and($events->first()->withoutOverlapping)->toBeTrue()
        ->and(SweepExpiredRoomsCommand::RETENTION_DAYS)->toBe(30);
});

/**
 * The export and the erasure, over one arrangement that puts the subject
 * in every column a user ULID can occupy in the shoutbox's tables:
 *
 * - author of two lines, one of them removed by somebody else;
 * - reporter of somebody else's line, with a note;
 * - moderator: removed somebody else's line, locked a room, muted
 *   somebody, resolved somebody else's report;
 * - muted by somebody else;
 * - holder of an admission badge.
 *
 * @return array<string, mixed>
 */
function shoutboxRetentionGdprScene(string $subjectId, string $otherId, string $thirdId): array
{
    $room = Room::factory()->create([
        'locked_at' => CarbonImmutable::parse('2026-10-17T22:00:00Z'),
        'locked_by_user_id' => $subjectId,
        'locked_reason' => 'Zu laut',
    ]);

    $ownLine = Message::factory()->for($room)->create(['author_user_id' => $subjectId, 'body' => 'Wer kommt mit zum Lötkurs?']);
    $ownRemovedLine = Message::factory()->for($room)->create([
        'author_user_id' => $subjectId,
        'body' => 'Das war zu viel.',
        'deleted_at' => CarbonImmutable::parse('2026-10-17T21:00:00Z'),
        'deleted_by_user_id' => $otherId,
        'deleted_reason' => 'Ton',
    ]);
    $othersLine = Message::factory()->for($room)->create([
        'author_user_id' => $otherId,
        'body' => 'Kauft Tickets bei mir!',
        'deleted_at' => CarbonImmutable::parse('2026-10-17T21:30:00Z'),
        'deleted_by_user_id' => $subjectId,
        'deleted_reason' => 'Spam',
    ]);

    return [
        'room' => $room,
        'ownLine' => $ownLine,
        'ownRemovedLine' => $ownRemovedLine,
        'othersLine' => $othersLine,
        // Somebody else's report on the subject's own line.
        'reportOnOwnLine' => MessageReport::factory()->for($ownLine)->create(['reporter_user_id' => $otherId, 'note' => 'Nervt']),
        // The subject's own report on somebody else's line.
        'ownReport' => MessageReport::factory()->for($othersLine)->create(['reporter_user_id' => $subjectId, 'note' => 'Werbung']),
        // A third person's report, resolved by the subject as moderator.
        'resolvedBySubject' => MessageReport::factory()->for($othersLine)->create([
            'reporter_user_id' => $thirdId,
            'note' => null,
            'state' => ReportState::Resolved,
            'resolved_by_user_id' => $subjectId,
            'resolved_at' => CarbonImmutable::parse('2026-10-17T21:31:00Z'),
        ]),
        'muteOnSubject' => RoomMute::factory()->for($room)->create([
            'user_id' => $subjectId,
            'muted_by_user_id' => $otherId,
            'reason' => 'Bitte kurz Pause',
            'expires_at' => CarbonImmutable::parse('2026-10-17T21:10:00Z'),
        ]),
        'muteBySubject' => RoomMute::factory()->for($room)->create([
            'user_id' => $otherId,
            'muted_by_user_id' => $subjectId,
            'reason' => 'Werbung',
            'expires_at' => null,
        ]),
        'ownBadge' => EventPresence::factory()->create(['user_id' => $subjectId, 'event_id' => $room->subject_id]),
        'othersBadge' => EventPresence::factory()->create(['user_id' => $otherId, 'event_id' => $room->subject_id]),
    ];
}

it('exports the subject\'s own lines, reports, mutes and badge, and nobody else\'s ULID', function (): void {
    $brand = $this->withBrand();

    $subjectId = (string) Str::ulid();
    $otherId = (string) Str::ulid();
    $thirdId = (string) Str::ulid();
    $scene = shoutboxRetentionGdprScene($subjectId, $otherId, $thirdId);

    $export = app(CommunityGdprHandler::class)->exportFor(SubjectRef::forUser($subjectId));

    $messages = collect($export['shoutbox_messages'])->keyBy('id');

    expect($messages->keys()->all())->toEqualCanonicalizing([$scene['ownLine']->id, $scene['ownRemovedLine']->id])
        ->and($messages[$scene['ownLine']->id]['body'])->toBe('Wer kommt mit zum Lötkurs?')
        ->and($messages[$scene['ownLine']->id]['brand_id'])->toBe($brand->getKey())
        ->and($messages[$scene['ownLine']->id]['room_id'])->toBe($scene['room']->id)
        ->and($messages[$scene['ownLine']->id]['removed_at'])->toBeNull()
        // A removed line is still the subject's own words, and they are told
        // it was removed and when — not by whom.
        ->and($messages[$scene['ownRemovedLine']->id]['body'])->toBe('Das war zu viel.')
        ->and($messages[$scene['ownRemovedLine']->id]['removed_at'])->toBe('2026-10-17T21:00:00+00:00');

    expect($export['shoutbox_reports'])->toHaveCount(1)
        ->and($export['shoutbox_reports'][0]['id'])->toBe($scene['ownReport']->id)
        ->and($export['shoutbox_reports'][0]['message_id'])->toBe($scene['othersLine']->id)
        ->and($export['shoutbox_reports'][0]['note'])->toBe('Werbung')
        ->and($export['shoutbox_reports'][0]['state'])->toBe('open');

    expect($export['shoutbox_mutes'])->toHaveCount(1)
        ->and($export['shoutbox_mutes'][0]['reason'])->toBe('Bitte kurz Pause')
        ->and($export['shoutbox_mutes'][0]['expires_at'])->toBe('2026-10-17T21:10:00+00:00');

    expect($export['event_presence'])->toHaveCount(1)
        ->and($export['event_presence'][0]['event_id'])->toBe($scene['room']->subject_id)
        ->and($export['event_presence'][0]['checked_in'])->toBeTrue();

    // Who removed the subject's line, who muted them, who reported them:
    // other people's identities, and none of the export's business.
    $encoded = (string) json_encode($export);

    expect($encoded)->not->toContain($otherId)
        ->and($encoded)->not->toContain($thirdId);
});

it('erases the subject\'s lines, anonymises their reports and moderator traces, and deletes their mutes and badge', function (): void {
    $this->withBrand();

    $subjectId = (string) Str::ulid();
    $otherId = (string) Str::ulid();
    $thirdId = (string) Str::ulid();
    $scene = shoutboxRetentionGdprScene($subjectId, $otherId, $thirdId);

    $handler = app(CommunityGdprHandler::class);
    $handler->delete(SubjectRef::forUser($subjectId), DeletionMode::Erase);

    // 1. Their lines are gone outright, removed or not, and so is the
    //    report somebody else filed on one of them.
    expect(Message::withoutBrandScope()->whereKey([$scene['ownLine']->id, $scene['ownRemovedLine']->id])->exists())->toBeFalse()
        ->and(MessageReport::withoutBrandScope()->whereKey($scene['reportOnOwnLine']->id)->exists())->toBeFalse();

    // 2. The report they filed stays as a fact about the message, with
    //    neither their ULID nor their words on it.
    $ownReport = MessageReport::withoutBrandScope()->findOrFail($scene['ownReport']->id);

    expect($ownReport->reporter_user_id)->toBeNull()
        ->and($ownReport->note)->toBeNull()
        ->and($ownReport->message_id)->toBe($scene['othersLine']->id)
        ->and($ownReport->state)->toBe(ReportState::Open);

    // 3. The badge goes with them; somebody else's stays.
    expect(EventPresence::withoutBrandScope()->whereKey($scene['ownBadge']->id)->exists())->toBeFalse()
        ->and(EventPresence::withoutBrandScope()->whereKey($scene['othersBadge']->id)->exists())->toBeTrue();

    // 4. Every moderator column that named them is nulled, and the row and
    //    the rest of its triple stay: the act happened to somebody else's
    //    line, room or report.
    $othersLine = Message::withoutBrandScope()->findOrFail($scene['othersLine']->id);
    $room = Room::withoutBrandScope()->findOrFail($scene['room']->id);
    $resolved = MessageReport::withoutBrandScope()->findOrFail($scene['resolvedBySubject']->id);
    $muteBySubject = RoomMute::withoutBrandScope()->findOrFail($scene['muteBySubject']->id);

    expect($othersLine->deleted_by_user_id)->toBeNull()
        ->and($othersLine->isRemoved())->toBeTrue()
        ->and($othersLine->deleted_reason)->toBe('Spam')
        ->and($room->locked_by_user_id)->toBeNull()
        ->and($room->locked_at?->toIso8601String())->toBe('2026-10-17T22:00:00+00:00')
        ->and($room->locked_reason)->toBe('Zu laut')
        ->and($resolved->resolved_by_user_id)->toBeNull()
        ->and($resolved->state)->toBe(ReportState::Resolved)
        ->and($resolved->resolved_at?->toIso8601String())->toBe('2026-10-17T21:31:00+00:00')
        ->and($resolved->reporter_user_id)->toBe($thirdId)
        ->and($muteBySubject->muted_by_user_id)->toBeNull()
        ->and($muteBySubject->user_id)->toBe($otherId)
        ->and($muteBySubject->reason)->toBe('Werbung');

    // 5. The mute held against them is about nobody else, and goes.
    expect(RoomMute::withoutBrandScope()->whereKey($scene['muteOnSubject']->id)->exists())->toBeFalse();

    // And the sweep of every user-ULID column in the shoutbox's tables:
    // the subject is nowhere.
    expect(Message::withoutBrandScope()->where('author_user_id', $subjectId)->orWhere('deleted_by_user_id', $subjectId)->exists())->toBeFalse()
        ->and(MessageReport::withoutBrandScope()->where('reporter_user_id', $subjectId)->orWhere('resolved_by_user_id', $subjectId)->exists())->toBeFalse()
        ->and(RoomMute::withoutBrandScope()->where('user_id', $subjectId)->orWhere('muted_by_user_id', $subjectId)->exists())->toBeFalse()
        ->and(Room::withoutBrandScope()->where('locked_by_user_id', $subjectId)->exists())->toBeFalse()
        ->and(EventPresence::withoutBrandScope()->where('user_id', $subjectId)->exists())->toBeFalse();

    // Idempotent: a second pass finds nothing and raises nothing.
    $handler->delete(SubjectRef::forUser($subjectId), DeletionMode::Erase);

    expect(MessageReport::withoutBrandScope()->whereKey($scene['ownReport']->id)->exists())->toBeTrue()
        ->and(Message::withoutBrandScope()->whereKey($scene['othersLine']->id)->exists())->toBeTrue();
});
