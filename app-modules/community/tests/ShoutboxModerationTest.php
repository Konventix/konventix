<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Konventix\Community\Actions\LockRoom;
use Konventix\Community\Actions\MuteParticipant;
use Konventix\Community\Actions\PostMessage;
use Konventix\Community\Actions\RemoveMessage;
use Konventix\Community\Actions\ReportMessage;
use Konventix\Community\Actions\ResolveReport;
use Konventix\Community\Actions\UnmuteParticipant;
use Konventix\Community\Data\MuteData;
use Konventix\Community\Data\PostMessageData;
use Konventix\Community\Data\ReportData;
use Konventix\Community\Enums\ReportState;
use Konventix\Community\Enums\ShoutboxRefusal;
use Konventix\Community\Events\ShoutboxMessageRemoved;
use Konventix\Community\Events\ShoutboxMessageReported;
use Konventix\Community\Exceptions\ShoutboxWriteException;
use Konventix\Community\Models\Message;
use Konventix\Community\Models\MessageReport;
use Konventix\Community\Models\Room;
use Konventix\Community\Models\RoomMute;
use Konventix\Platform\Models\Brand;

/**
 * The three moderation verbs and the report queue (design §4.2–§4.4, §6,
 * §9; D-COM-01, D-COM-04, D-COM-08; task-3-brief.md).
 *
 * **Nothing here touches `community_event_presence`, and that is the
 * point.** D-COM-04 gates moderation on capability alone — an organizer
 * who was never scanned in must still be able to act — and ADR-0067
 * decision 2 forbids Community enforcing anything on the badge. Every case
 * below moderates with a bare ULID for the moderator, no admission
 * anywhere, and succeeds.
 *
 * The capability gate itself (`Permission::ModerateCommunity`) belongs to
 * the surfaces (Task 5); these are the Actions underneath it.
 */
it('leaves a tombstone a report still resolves against', function (): void {
    $this->withBrand();

    $room = Room::factory()->create();
    $authorId = (string) Str::ulid();
    $reporterId = (string) Str::ulid();
    $moderatorId = (string) Str::ulid();

    $message = app(PostMessage::class)->handle($room, $authorId, new PostMessageData('Unfug'));
    $report = app(ReportMessage::class)->handle($message, $reporterId, new ReportData('Beleidigend'));

    app(RemoveMessage::class)->handle($message, $moderatorId, 'Insult');

    $resolved = app(ResolveReport::class)->handle($report, $moderatorId, ReportState::Resolved);

    // `Message` deliberately does not use `SoftDeletes` (design §4.2): the
    // row stays visible so the room can render it as "Von der Orga
    // entfernt" and so the desk can see what it acted on. An ordinary
    // query — no `withTrashed()` — must still find it.
    $tombstone = Message::query()->find($message->id);

    expect($tombstone)->not->toBeNull()
        ->and($tombstone->isRemoved())->toBeTrue()
        // The tombstone triple, all three or none — no constraint ties them.
        ->and($tombstone->deleted_at)->not->toBeNull()
        ->and($tombstone->deleted_by_user_id)->toBe($moderatorId)
        ->and($tombstone->deleted_reason)->toBe('Insult')
        // The report survives the removal and closes against it.
        ->and($resolved->state)->toBe(ReportState::Resolved)
        ->and($resolved->resolved_by_user_id)->toBe($moderatorId)
        ->and($resolved->resolved_at)->not->toBeNull()
        ->and(MessageReport::query()->find($report->id)->state)->toBe(ReportState::Resolved);
});

it('reports a message the desk has already removed', function (): void {
    $this->withBrand();

    $room = Room::factory()->create();
    $message = app(PostMessage::class)->handle($room, (string) Str::ulid(), new PostMessageData('Unfug'));

    app(RemoveMessage::class)->handle($message, (string) Str::ulid(), 'Insult');

    // The ordinary race between a reporter and a moderator; the tombstone
    // is exactly what keeps it from being a dangling row.
    $report = app(ReportMessage::class)->handle($message, (string) Str::ulid(), new ReportData(null));

    expect($report->exists)->toBeTrue()
        ->and($report->state)->toBe(ReportState::Open);
});

it('removes a line once, however often the button is pressed', function (): void {
    $this->withBrand();
    Event::fake([ShoutboxMessageRemoved::class]);

    $room = Room::factory()->create();
    $message = Message::factory()->for($room)->create(['body' => 'Unfug']);
    $firstModerator = (string) Str::ulid();

    app(RemoveMessage::class)->handle($message, $firstModerator, 'Insult');
    // A freshly loaded row, so the guard is proved to read persisted state
    // rather than the instance it just wrote to.
    app(RemoveMessage::class)->handle(Message::query()->findOrFail($message->id), (string) Str::ulid(), 'Zweiter Klick');

    Event::assertDispatchedTimes(ShoutboxMessageRemoved::class, 1);

    // The second call must not rewrite who removed it or why.
    expect(Message::query()->find($message->id)->deleted_by_user_id)->toBe($firstModerator)
        ->and(Message::query()->find($message->id)->deleted_reason)->toBe('Insult');
});

it('publishes ShoutboxMessageRemoved once, carrying the desk\'s reason and no body', function (): void {
    $brand = $this->withBrand();
    Event::fake([ShoutboxMessageRemoved::class]);

    $room = Room::factory()->create();
    $message = Message::factory()->for($room)->create(['body' => 'KANARIENVOGEL im Loetkurs']);
    $moderatorId = (string) Str::ulid();

    app(RemoveMessage::class)->handle($message, $moderatorId, 'Insult');

    $dispatched = Event::dispatched(ShoutboxMessageRemoved::class);
    expect($dispatched)->toHaveCount(1);

    /** @var ShoutboxMessageRemoved $removed */
    $removed = $dispatched->first()[0];
    $payload = (array) $removed;

    // Design §9: the organizer's reason travels, the participant's words do
    // not. Asserted as an exact key set *and* as a search for the text, so
    // that neither a renamed field nor an added one can smuggle the body
    // past this test.
    expect(array_keys($payload))
        ->toBe(['messageId', 'roomId', 'brandId', 'removedByUserId', 'reason', 'removedAt'])
        ->and(implode(' ', array_map(strval(...), $payload)))->not->toContain('KANARIENVOGEL')
        ->and($removed->messageId)->toBe($message->id)
        ->and($removed->roomId)->toBe($room->id)
        ->and($removed->brandId)->toBe($brand->getKey())
        ->and($removed->removedByUserId)->toBe($moderatorId)
        ->and($removed->reason)->toBe('Insult');
});

it('queues one report per reporter and publishes ShoutboxMessageReported once, carrying neither body nor note', function (): void {
    $brand = $this->withBrand();
    Event::fake([ShoutboxMessageReported::class]);

    $room = Room::factory()->create();
    $message = Message::factory()->for($room)->create(['body' => 'KANARIENVOGEL im Loetkurs']);
    $reporterId = (string) Str::ulid();

    $first = app(ReportMessage::class)->handle($message, $reporterId, new ReportData('ZAUNKOENIG beleidigend'));
    // `unique(message_id, reporter_user_id)` (design §4.4): the same person
    // pressing the button twice files the same report, and must not raise a
    // constraint violation at them.
    $second = app(ReportMessage::class)->handle($message, $reporterId, new ReportData('ZAUNKOENIG nochmal'));

    Event::assertDispatchedTimes(ShoutboxMessageReported::class, 1);

    $dispatched = Event::dispatched(ShoutboxMessageReported::class);

    /** @var ShoutboxMessageReported $reported */
    $reported = $dispatched->first()[0];
    $payload = (array) $reported;

    expect($second->id)->toBe($first->id)
        ->and(MessageReport::query()->where('message_id', $message->id)->count())->toBe(1)
        ->and($first->note)->toBe('ZAUNKOENIG beleidigend')
        ->and(array_keys($payload))
        ->toBe(['reportId', 'messageId', 'roomId', 'brandId', 'reporterUserId', 'reportedAt'])
        // Neither the reported line nor the reporter's own words travel.
        ->and(implode(' ', array_map(strval(...), $payload)))->not->toContain('KANARIENVOGEL')
        ->and(implode(' ', array_map(strval(...), $payload)))->not->toContain('ZAUNKOENIG')
        ->and($reported->reportId)->toBe($first->id)
        ->and($reported->messageId)->toBe($message->id)
        ->and($reported->roomId)->toBe($room->id)
        ->and($reported->brandId)->toBe($brand->getKey())
        ->and($reported->reporterUserId)->toBe($reporterId);
});

it('dismisses a report and refuses to reopen one', function (): void {
    $this->withBrand();

    $room = Room::factory()->create();
    $message = Message::factory()->for($room)->create();
    $report = app(ReportMessage::class)->handle($message, (string) Str::ulid(), new ReportData(null));
    $moderatorId = (string) Str::ulid();

    $dismissed = app(ResolveReport::class)->handle($report, $moderatorId, ReportState::Dismissed);

    expect($dismissed->state)->toBe(ReportState::Dismissed)
        ->and($dismissed->resolved_by_user_id)->toBe($moderatorId)
        ->and($dismissed->resolved_at)->not->toBeNull()
        // "Un-resolve" is not one of D-COM-08's verbs.
        ->and(fn () => app(ResolveReport::class)->handle($report, $moderatorId, ReportState::Open))
        ->toThrow(InvalidArgumentException::class);
});

it('writes a mute as a log row with a required reason and a timed expiry', function (): void {
    $this->withBrand();
    $this->travelTo(CarbonImmutable::parse('2026-10-17 20:00:00Z'));

    $room = Room::factory()->create();
    $userId = (string) Str::ulid();
    $moderatorId = (string) Str::ulid();

    $mute = app(MuteParticipant::class)->handle($room, $userId, $moderatorId, 'Spam', 10);

    expect($mute->reason)->toBe('Spam')
        ->and($mute->muted_by_user_id)->toBe($moderatorId)
        ->and($mute->expires_at?->equalTo(CarbonImmutable::parse('2026-10-17 20:10:00Z')))->toBeTrue()
        ->and($mute->brand_id)->toBe($room->brand_id)
        ->and(fn () => app(MuteParticipant::class)->handle($room, $userId, $moderatorId, '   ', 10))
        ->toThrow(InvalidArgumentException::class);
});

it('lifts every mute in force and leaves the log behind', function (): void {
    $this->withBrand();
    $this->travelTo(CarbonImmutable::parse('2026-10-17 20:00:00Z'));

    $room = Room::factory()->create();
    $userId = (string) Str::ulid();
    $moderatorId = (string) Str::ulid();

    // A timed mute under an indefinite one: lifting only the row the
    // console happened to show would leave the person silenced with
    // nothing on screen to explain it.
    app(MuteParticipant::class)->handle($room, $userId, $moderatorId, 'Spam', 10);
    app(MuteParticipant::class)->handle($room, $userId, $moderatorId, 'Dauerhaft', null);

    $lifted = app(UnmuteParticipant::class)->handle($room, $userId);

    $rows = RoomMute::query()->where('room_id', $room->id)->where('user_id', $userId)->get();

    expect($lifted)->toBe(2)
        // Expired, not deleted — the moderation history stays readable.
        ->and($rows)->toHaveCount(2)
        ->and($rows->pluck('reason')->sort()->values()->all())->toBe(['Dauerhaft', 'Spam'])
        ->and($rows->filter(fn (RoomMute $row): bool => $row->isActiveAt(CarbonImmutable::now()->utc())))->toBeEmpty()
        // And the person can write again.
        ->and(app(PostMessage::class)->handle($room, $userId, new PostMessageData('Wieder da'))->exists)->toBeTrue();
});

it('leaves somebody else\'s mute alone when it lifts one', function (): void {
    $this->withBrand();
    $this->freezeTime();

    $room = Room::factory()->create();
    $userId = (string) Str::ulid();
    $otherUserId = (string) Str::ulid();
    $moderatorId = (string) Str::ulid();

    app(MuteParticipant::class)->handle($room, $userId, $moderatorId, 'Spam', 10);
    app(MuteParticipant::class)->handle($room, $otherUserId, $moderatorId, 'Spam', 10);

    expect(app(UnmuteParticipant::class)->handle($room, $userId))->toBe(1)
        ->and(app(UnmuteParticipant::class)->handle($room, $userId))->toBe(0)
        ->and(RoomMute::query()
            ->where('room_id', $room->id)
            ->where('user_id', $otherUserId)
            ->get()
            ->filter(fn (RoomMute $row): bool => $row->isActiveAt(CarbonImmutable::now()->utc())))
        ->toHaveCount(1);
});

it('sets the lock triple together and does not rewrite it on a second lock', function (): void {
    $this->withBrand();

    $room = Room::factory()->create();
    $firstModerator = (string) Str::ulid();

    app(LockRoom::class)->handle($room, $firstModerator, 'Eskaliert');
    // Freshly loaded, so the guard is proved to read persisted state rather
    // than the instance it just wrote to.
    app(LockRoom::class)->handle(Room::query()->findOrFail($room->id), (string) Str::ulid(), 'Zweiter Klick');

    $room->refresh();

    // No constraint ties the three columns; the Action is the only thing
    // that keeps them consistent.
    expect($room->locked_at)->not->toBeNull()
        ->and($room->locked_by_user_id)->toBe($firstModerator)
        ->and($room->locked_reason)->toBe('Eskaliert');
});

it('holds the removal and report events until the caller\'s own transaction commits', function (): void {
    $this->withBrand();
    Event::fake([ShoutboxMessageRemoved::class, ShoutboxMessageReported::class]);

    $room = Room::factory()->create();
    $message = Message::factory()->for($room)->create();

    // Filing the desk's own report and removing the line in one unit is an
    // ordinary thing for a console verb to do — and it turns each Action's
    // `DB::transaction()` into a savepoint, so a dispatch placed after that
    // transaction returns would broadcast a removal the outer transaction
    // could still roll back.
    DB::transaction(function () use ($message): void {
        app(ReportMessage::class)->handle($message, (string) Str::ulid(), new ReportData(null));
        app(RemoveMessage::class)->handle($message, (string) Str::ulid(), 'Insult');

        Event::assertNotDispatched(ShoutboxMessageReported::class);
        Event::assertNotDispatched(ShoutboxMessageRemoved::class);
    });

    Event::assertDispatchedTimes(ShoutboxMessageReported::class, 1);
    Event::assertDispatchedTimes(ShoutboxMessageRemoved::class, 1);
});

it('refuses a moderation reason longer than its column, in the Action rather than at the database', function (): void {
    $this->withBrand();

    $room = Room::factory()->create();
    $message = Message::factory()->for($room)->create();
    $moderatorId = (string) Str::ulid();

    // All three columns are a `varchar(255)`, and `SQLSTATE[22001] value
    // too long` is a 500 that takes the surrounding transaction with it
    // (.ai/rules/tests.md). Each Action is what has to say no, and each
    // reads the constant that names its own column.
    expect(fn () => app(RemoveMessage::class)->handle($message, $moderatorId, str_repeat('a', RemoveMessage::MAX_REASON_LENGTH + 1)))
        ->toThrow(InvalidArgumentException::class)
        ->and(fn () => app(MuteParticipant::class)->handle($room, (string) Str::ulid(), $moderatorId, str_repeat('a', MuteData::MAX_REASON_LENGTH + 1), 10))
        ->toThrow(InvalidArgumentException::class)
        ->and(fn () => app(LockRoom::class)->handle($room, $moderatorId, str_repeat('a', LockRoom::MAX_REASON_LENGTH + 1)))
        ->toThrow(InvalidArgumentException::class)
        // Refused before any of them wrote anything.
        ->and(Message::query()->findOrFail($message->id)->deleted_at)->toBeNull()
        ->and(RoomMute::query()->where('room_id', $room->id)->count())->toBe(0)
        ->and($room->refresh()->locked_at)->toBeNull();

    // The boundary is the column's own length, not one short of it.
    $atTheLimit = app(RemoveMessage::class)->handle($message, $moderatorId, str_repeat('b', RemoveMessage::MAX_REASON_LENGTH));

    expect($atTheLimit->deleted_reason)->toHaveLength(RemoveMessage::MAX_REASON_LENGTH);
});

it('refuses a report note longer than its column, in German and with a cause the page can branch on', function (): void {
    $this->withBrand();

    $room = Room::factory()->create();
    $message = Message::factory()->for($room)->create();

    // The one length a *participant* reaches directly: a client that
    // ignores the form's `maxlength` must be told what is wrong, not handed
    // a 500 — which is exactly what the body cap exists to prevent.
    try {
        app(ReportMessage::class)->handle(
            $message,
            (string) Str::ulid(),
            new ReportData(str_repeat('a', ReportData::MAX_NOTE_LENGTH + 1)),
        );
        $this->fail('A note longer than its column was accepted.');
    } catch (ShoutboxWriteException $refusal) {
        expect($refusal->getMessage())->toContain('maximal 255 Zeichen')
            ->and($refusal->cause)->toBe(ShoutboxRefusal::NoteTooLong)
            // On the note field, not on the compose box.
            ->and($refusal->cause->field())->toBe('note');
    }

    $atTheLimit = app(ReportMessage::class)->handle(
        $message,
        (string) Str::ulid(),
        new ReportData(str_repeat('b', ReportData::MAX_NOTE_LENGTH)),
    );

    expect(MessageReport::query()->where('message_id', $message->id)->count())->toBe(1)
        ->and($atTheLimit->note)->toHaveLength(ReportData::MAX_NOTE_LENGTH);
});

it('keeps the first resolver when a second moderator closes the same report', function (): void {
    $this->withBrand();
    $this->travelTo(CarbonImmutable::parse('2026-10-17 02:10:00Z'));

    $room = Room::factory()->create();
    $message = Message::factory()->for($room)->create();
    $report = app(ReportMessage::class)->handle($message, (string) Str::ulid(), new ReportData(null));
    $firstModerator = (string) Str::ulid();

    app(ResolveReport::class)->handle($report, $firstModerator, ReportState::Resolved);
    $afterFirst = MessageReport::query()->findOrFail($report->id);

    // 04:30, a console tab loaded before 02:10, and a click on Dismiss.
    // Both states are terminal, so the second act is a no-op — freshly
    // loaded, so the guard is proved to read persisted state rather than
    // the instance the first call wrote to.
    $this->travelTo(CarbonImmutable::parse('2026-10-17 04:30:00Z'));

    $returned = app(ResolveReport::class)->handle(
        MessageReport::query()->findOrFail($report->id),
        (string) Str::ulid(),
        ReportState::Dismissed,
    );

    $stored = MessageReport::query()->findOrFail($report->id);

    expect($returned->state)->toBe(ReportState::Resolved)
        ->and($stored->state)->toBe(ReportState::Resolved)
        // Losing who worked the item is the whole point of the guard.
        ->and($stored->resolved_by_user_id)->toBe($firstModerator)
        ->and($stored->resolved_at?->equalTo($afterFirst->resolved_at))->toBeTrue();
});

it('answers a report that lost the race with the report already on file', function (): void {
    $this->withBrand();
    Event::fake([ShoutboxMessageReported::class]);

    $room = Room::factory()->create();
    $message = Message::factory()->for($room)->create();
    $reporterId = (string) Str::ulid();

    // A genuinely simultaneous pair cannot be staged in one process on one
    // connection, so the *miss* is staged instead: the row exists, but
    // under a brand the Action's own read filters out. That is exactly the
    // state the losing request is in — it reads nothing and then meets
    // `unique(message_id, reporter_user_id)`, which is brand-blind.
    $onFile = new MessageReport([
        'message_id' => $message->id,
        'reporter_user_id' => $reporterId,
        'note' => 'Zuerst',
        'state' => ReportState::Open,
    ]);
    // A real brand: `brand_id` is a foreign key onto `brands`, and an
    // invented ULID fails that constraint before the scenario is staged.
    $onFile->forceFill(['brand_id' => Brand::factory()->create()->getKey()])->save();

    $answer = app(ReportMessage::class)->handle($message, $reporterId, new ReportData('Gleichzeitig'));

    // One act, one report, one event — and no QueryException at somebody
    // who pressed a button twice in the same instant.
    expect($answer->id)->toBe($onFile->id)
        ->and($answer->note)->toBe('Zuerst')
        ->and(MessageReport::withoutBrandScope()->where('message_id', $message->id)->count())->toBe(1);

    Event::assertNotDispatched(ShoutboxMessageReported::class);
});

it('leaves a closed room alone rather than locking it shut for good', function (): void {
    $this->withBrand();

    $room = Room::factory()->create(['closed_at' => CarbonImmutable::now()->utc()]);

    app(LockRoom::class)->handle($room, (string) Str::ulid(), 'Eskaliert');

    // `UnlockRoom` refuses to clear a lock on a closed room, so a lock
    // applied to one would be permanent and the console would show the wall
    // as "gesperrt" with nothing able to clear it. A closed wall refuses
    // every post on its own, so the lock buys nothing either.
    expect($room->refresh()->locked_at)->toBeNull()
        ->and($room->locked_by_user_id)->toBeNull()
        ->and($room->locked_reason)->toBeNull();
});
