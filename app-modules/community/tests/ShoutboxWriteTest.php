<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Konventix\Community\Actions\LockRoom;
use Konventix\Community\Actions\MuteParticipant;
use Konventix\Community\Actions\PostMessage;
use Konventix\Community\Actions\UnlockRoom;
use Konventix\Community\Data\PostMessageData;
use Konventix\Community\Events\ShoutboxMessagePosted;
use Konventix\Community\Exceptions\ShoutboxWriteException;
use Konventix\Community\Models\Message;
use Konventix\Community\Models\Room;

/**
 * `PostMessage` — the write path (design §6, §9, D-COM-09, task-3-brief.md).
 *
 * Every rule here is asserted **through the Action**, never through a
 * controller: spec §6 puts the enforcement in the Action precisely so that
 * a client which ignores the compose box is still refused, and a test that
 * went through a route would prove the route instead.
 *
 * Time is frozen or travelled in every case that asks about a window: the
 * rate limit is ten seconds wide and a mute is ten minutes, so a test
 * trusting the wall clock would be a test whose result depends on how busy
 * the runner was.
 */
it('refuses the sixth message in ten seconds', function (): void {
    $this->withBrand();
    $this->freezeTime();

    $room = Room::factory()->create();
    $userId = (string) Str::ulid();
    $post = app(PostMessage::class);

    foreach (range(1, 5) as $index) {
        $post->handle($room, $userId, new PostMessageData("Nachricht {$index}"));
    }

    expect(fn () => $post->handle($room, $userId, new PostMessageData('Nachricht 6')))
        ->toThrow(ShoutboxWriteException::class, 'Du schreibst zu schnell')
        // The sixth must not have landed either: a refusal that still wrote
        // the row would be a limit in the message only.
        ->and(Message::query()->where('room_id', $room->id)->count())->toBe(5);
});

it('spends the posting budget per person and per room, not globally', function (): void {
    $this->withBrand();
    $this->freezeTime();

    $room = Room::factory()->create();
    $otherRoom = Room::factory()->create();
    $userId = (string) Str::ulid();
    $otherUserId = (string) Str::ulid();
    $post = app(PostMessage::class);

    foreach (range(1, 5) as $index) {
        $post->handle($room, $userId, new PostMessageData("Nachricht {$index}"));
    }

    // Same person, different room, and a different person in the spent
    // room: both are untouched budgets (D-COM-09 is per person per room).
    $elsewhere = $post->handle($otherRoom, $userId, new PostMessageData('Anderer Raum'));
    $somebodyElse = $post->handle($room, $otherUserId, new PostMessageData('Andere Person'));

    expect($elsewhere->exists)->toBeTrue()
        ->and($somebodyElse->exists)->toBeTrue()
        ->and(fn () => $post->handle($room, $userId, new PostMessageData('Nachricht 6')))
        ->toThrow(ShoutboxWriteException::class);
});

it('refuses a muted participant and names the reason and the remaining time', function (): void {
    $this->withBrand();
    $this->freezeTime();

    $room = Room::factory()->create();
    $userId = (string) Str::ulid();

    app(MuteParticipant::class)->handle($room, $userId, (string) Str::ulid(), 'Spam', 10);

    // D-COM-08: the muted person is shown the reason and how long it runs —
    // a silent mute reads as a broken app.
    expect(fn () => app(PostMessage::class)->handle($room, $userId, new PostMessageData('Hallo')))
        ->toThrow(ShoutboxWriteException::class, 'Du kannst 10 Minuten nicht schreiben: Spam')
        ->and(Message::query()->where('room_id', $room->id)->count())->toBe(0);
});

it('refuses a participant muted for the rest of the event, with no expiry to name', function (): void {
    $this->withBrand();
    $this->freezeTime();

    $room = Room::factory()->create();
    $userId = (string) Str::ulid();

    // `null` minutes is the indefinite mute of design §4.3 — `expires_at`
    // stays null, and the gate must read that as "still muted" rather than
    // as "no mute".
    $mute = app(MuteParticipant::class)->handle($room, $userId, (string) Str::ulid(), 'Dauerhaft', null);

    expect($mute->expires_at)->toBeNull()
        ->and(fn () => app(PostMessage::class)->handle($room, $userId, new PostMessageData('Hallo')))
        ->toThrow(ShoutboxWriteException::class, 'Du kannst bis zum Ende des Events nicht schreiben: Dauerhaft');
});

it('lets a participant post again once the mute has expired', function (): void {
    $this->withBrand();
    $this->travelTo(CarbonImmutable::parse('2026-10-17 20:00:00Z'));

    $room = Room::factory()->create();
    $userId = (string) Str::ulid();

    app(MuteParticipant::class)->handle($room, $userId, (string) Str::ulid(), 'Spam', 10);

    expect(fn () => app(PostMessage::class)->handle($room, $userId, new PostMessageData('Zu frueh')))
        ->toThrow(ShoutboxWriteException::class);

    $this->travelTo(CarbonImmutable::parse('2026-10-17 20:11:00Z'));

    $message = app(PostMessage::class)->handle($room, $userId, new PostMessageData('Wieder da'));

    expect($message->body)->toBe('Wieder da')
        ->and(Message::query()->where('room_id', $room->id)->pluck('body')->all())->toBe(['Wieder da']);
});

it('refuses every post in a locked room and still reads the room', function (): void {
    $this->withBrand();

    $room = Room::factory()->create();
    $userId = (string) Str::ulid();

    app(PostMessage::class)->handle($room, $userId, new PostMessageData('Vor dem Lock'));

    app(LockRoom::class)->handle($room, (string) Str::ulid(), 'Eskaliert');

    expect(fn () => app(PostMessage::class)->handle($room, $userId, new PostMessageData('Nach dem Lock')))
        ->toThrow(ShoutboxWriteException::class, 'Die Wall ist gerade gesperrt')
        // Readable, not writable (design §6): the line posted before the
        // lock is still there.
        ->and(Message::query()->where('room_id', $room->id)->pluck('body')->all())->toBe(['Vor dem Lock']);
});

it('accepts posts again once the room is unlocked', function (): void {
    $this->withBrand();

    $room = Room::factory()->create();
    $userId = (string) Str::ulid();

    app(LockRoom::class)->handle($room, (string) Str::ulid(), 'Eskaliert');
    app(UnlockRoom::class)->handle($room);

    $message = app(PostMessage::class)->handle($room, $userId, new PostMessageData('Wieder offen'));

    expect($message->exists)->toBeTrue()
        ->and($room->refresh()->locked_at)->toBeNull()
        ->and($room->locked_by_user_id)->toBeNull()
        ->and($room->locked_reason)->toBeNull();
});

it('refuses every post in a closed room, which unlocking cannot reopen', function (): void {
    $this->withBrand();

    // `closed_at` is `EventConcluded`'s and starts the retention clock
    // (design §4.1, §7). A closed room reads until the sweep takes it and
    // never writes again — so the lock that was in force when the event
    // ended stays in force, and unlocking is refused rather than obeyed.
    $room = Room::factory()->create([
        'locked_at' => CarbonImmutable::now()->utc(),
        'locked_by_user_id' => (string) Str::ulid(),
        'locked_reason' => 'Eskaliert',
        'closed_at' => CarbonImmutable::now()->utc(),
    ]);
    $userId = (string) Str::ulid();

    app(UnlockRoom::class)->handle($room);

    expect($room->refresh()->locked_at)->not->toBeNull()
        ->and(fn () => app(PostMessage::class)->handle($room, $userId, new PostMessageData('Hallo')))
        ->toThrow(ShoutboxWriteException::class, 'beendet');
});

it('refuses a body over the 500-character cap in the Action, not at the column', function (): void {
    $this->withBrand();

    $room = Room::factory()->create();

    // 501 characters: `varchar(500)` would refuse this too, but with a
    // QueryException and a 500 rather than an answer. The Action has to be
    // the thing that says no (design §6).
    expect(fn () => app(PostMessage::class)->handle($room, (string) Str::ulid(), new PostMessageData(str_repeat('a', 501))))
        ->toThrow(ShoutboxWriteException::class, 'maximal 500 Zeichen')
        ->and(Message::query()->where('room_id', $room->id)->count())->toBe(0);
});

it('refuses a body that is only whitespace', function (): void {
    $this->withBrand();

    $room = Room::factory()->create();

    expect(fn () => app(PostMessage::class)->handle($room, (string) Str::ulid(), new PostMessageData("   \n  ")))
        ->toThrow(ShoutboxWriteException::class, 'leer');
});

it('publishes ShoutboxMessagePosted once, carrying no body', function (): void {
    $brand = $this->withBrand();
    Event::fake([ShoutboxMessagePosted::class]);

    $room = Room::factory()->create();
    $userId = (string) Str::ulid();

    // A needle no id, timestamp or ULID can contain by accident.
    $message = app(PostMessage::class)->handle($room, $userId, new PostMessageData('KANARIENVOGEL im Loetkurs'));

    $dispatched = Event::dispatched(ShoutboxMessagePosted::class);
    expect($dispatched)->toHaveCount(1);

    /** @var ShoutboxMessagePosted $posted */
    $posted = $dispatched->first()[0];
    $payload = (array) $posted;

    // Design §9 and ADR-0004: ULIDs and scalars only. Asserted as an exact
    // key set *and* as a search for the text, so that neither a renamed
    // field nor an added one can smuggle the body past this test.
    expect(array_keys($payload))
        ->toBe(['messageId', 'roomId', 'brandId', 'eventId', 'authorUserId', 'postedAt'])
        ->and(implode(' ', array_map(strval(...), $payload)))->not->toContain('KANARIENVOGEL')
        ->and($posted->messageId)->toBe($message->id)
        ->and($posted->roomId)->toBe($room->id)
        ->and($posted->brandId)->toBe($brand->getKey())
        ->and($posted->eventId)->toBe($room->subject_id)
        ->and($posted->authorUserId)->toBe($userId);
});
