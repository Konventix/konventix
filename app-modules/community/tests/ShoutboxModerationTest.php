<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Konventix\Community\Actions\LockRoom;
use Konventix\Community\Actions\MuteParticipant;
use Konventix\Community\Actions\PostMessage;
use Konventix\Community\Actions\RemoveMessage;
use Konventix\Community\Actions\ReportMessage;
use Konventix\Community\Actions\ResolveReport;
use Konventix\Community\Actions\UnmuteParticipant;
use Konventix\Community\Data\PostMessageData;
use Konventix\Community\Data\ReportData;
use Konventix\Community\Enums\ReportState;
use Konventix\Community\Events\ShoutboxMessageRemoved;
use Konventix\Community\Events\ShoutboxMessageReported;
use Konventix\Community\Models\Message;
use Konventix\Community\Models\MessageReport;
use Konventix\Community\Models\Room;
use Konventix\Community\Models\RoomMute;

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
