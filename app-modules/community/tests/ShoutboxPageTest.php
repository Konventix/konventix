<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Konventix\Community\Actions\LockRoom;
use Konventix\Community\Actions\MuteParticipant;
use Konventix\Community\Actions\PostMessage;
use Konventix\Community\Actions\RemoveMessage;
use Konventix\Community\Data\PostMessageData;
use Konventix\Community\Data\ReportData;
use Konventix\Community\Enums\RoomSubject;
use Konventix\Community\Models\CommunityProfile;
use Konventix\Community\Models\Message;
use Konventix\Community\Models\MessageReport;
use Konventix\Community\Models\Room;
use Konventix\Community\Presence\ConnectionState;
use Konventix\Identity\Models\User;
use Konventix\Platform\Models\Brand;

/**
 * The participant's surfaces (design §8, D-COM-03): the hub card
 * `community.shoutbox`, the room at `/live/shoutbox`, and the JSON endpoints
 * both of them talk to.
 *
 * Admission is arranged the way production arranges it — by emitting
 * Admission's `ParticipantAdmitted` (`hubAdmitted()`, Platform's shared
 * helper), which Platform projects into the hub's access and Community into
 * its badge. Nothing here writes either projection by hand.
 *
 * The room is never created by a test in this file: the first read creates
 * it (spec §4.1), and several tests assert exactly that.
 *
 * Helpers are prefixed `shoutboxPage*` — Pest helpers are process-global
 * across every module's suite (`.ai/rules/tests.md`).
 */

/**
 * A member admitted to `$eventId` on `$brand`, with a community profile so
 * that their lines carry a name.
 */
function shoutboxPageAdmitted(Brand $brand, string $eventId, string $name): User
{
    $user = User::factory()->create();

    CommunityProfile::query()->create([
        'id' => $user->id,
        'username' => Str::lower($name).'-'.Str::lower(Str::random(6)),
        'display_name' => $name,
    ]);

    event(hubAdmitted($brand->getKey(), $user->id, $eventId));

    return $user;
}

/**
 * Skips rather than failing the suite on an infrastructure gap — but CI's
 * `test:php` job runs Redis beside it, so this must run there, not skip.
 */
function shoutboxPageRedisReachable(): bool
{
    try {
        Redis::connection()->ping();

        return true;
    } catch (Throwable) {
        return false;
    }
}

it('lets an admitted participant post to the wall and read it, creating the room on first use', function (): void {
    $brand = $this->withBrand();
    $this->activateModule('community', $brand);

    $eventId = (string) Str::ulid();
    $ada = shoutboxPageAdmitted($brand, $eventId, 'Ada');
    $bob = shoutboxPageAdmitted($brand, $eventId, 'Bob');

    $this->actingAs($bob)
        ->postJson('/live/shoutbox/messages', ['body' => 'Wo ist die Schlange zum Lötkurs?'])
        ->assertCreated()
        ->assertJsonPath('authorUserId', $bob->id)
        ->assertJsonPath('authorDisplayName', 'Bob')
        ->assertJsonPath('body', 'Wo ist die Schlange zum Lötkurs?');

    // Lazily, on the first read, keyed on the brand as well as the subject
    // (spec §4.1).
    $room = Room::withoutBrandScope()->sole();

    expect($room->brand_id)->toBe($brand->getKey())
        ->and($room->subject_type)->toBe(RoomSubject::Event)
        ->and($room->subject_id)->toBe($eventId);

    $this->actingAs($ada)
        ->get('/live/shoutbox')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('community/live/Room')
            ->where('room.eventName', 'Kon Fest')
            ->where('room.wall.roomId', $room->id)
            ->has('room.wall.lines', 1)
            ->where('room.wall.lines.0.authorDisplayName', 'Bob')
            ->where('room.wall.lines.0.body', 'Wo ist die Schlange zum Lötkurs?')
            ->where('room.wall.hasOlder', false)
            ->where('room.wall.notice', null)
            ->where('room.wall.mute', null)
            ->where('room.wall.maxLength', PostMessageData::MAX_BODY_LENGTH)
            ->where('room.noteMaxLength', ReportData::MAX_NOTE_LENGTH));
});

it('answers a member nobody admitted with a 404 on the page and on every endpoint the card uses', function (): void {
    $brand = $this->withBrand();
    $this->activateModule('community', $brand);

    // Somebody is admitted, so the event's wall is real — just not theirs.
    $eventId = (string) Str::ulid();
    shoutboxPageAdmitted($brand, $eventId, 'Ada');

    $stranger = User::factory()->create();

    $this->actingAs($stranger)->get('/live/shoutbox')->assertNotFound();
    $this->actingAs($stranger)->getJson('/live/shoutbox/messages?after='.Str::ulid())->assertNotFound();
    $this->actingAs($stranger)->postJson('/live/shoutbox/messages', ['body' => 'Hallo?'])->assertNotFound();
    $this->actingAs($stranger)->postJson('/live/shoutbox/presence', ['status' => 'online'])->assertNotFound();

    // And the hub the card lives on does not render for them at all.
    $this->actingAs($stranger)->get('/live')->assertRedirect('/');

    expect(Message::withoutBrandScope()->count())->toBe(0)
        ->and(Room::withoutBrandScope()->count())->toBe(0);
});

it('puts the shoutbox card on the hub of an admitted participant', function (): void {
    $brand = $this->withBrand();
    $this->activateModule('community', $brand);

    $eventId = (string) Str::ulid();
    $ada = shoutboxPageAdmitted($brand, $eventId, 'Ada');

    $cards = [];

    $this->actingAs($ada)->get('/live')->assertOk()->assertInertia(function (Assert $page) use (&$cards): void {
        /** @var list<array<string, mixed>> $rendered */
        $rendered = $page->toArray()['props']['hub']['cards'];
        $cards = collect($rendered)->keyBy('key')->all();
    });

    $room = Room::withoutBrandScope()->sole();

    expect($cards)->toHaveKey('community.shoutbox')
        ->and($cards['community.shoutbox']['component'])->toBe('community/hub/ShoutboxCard')
        ->and($cards['community.shoutbox']['payload']['wall']['roomId'])->toBe($room->id)
        ->and($cards['community.shoutbox']['payload']['wall']['lines'])->toBe([])
        ->and($cards['community.shoutbox']['payload']['roomUrl'])->toBe('/live/shoutbox')
        ->and($cards['community.shoutbox']['payload']['messagesUrl'])->toBe('/live/shoutbox/messages')
        ->and($cards['community.shoutbox']['payload']['postUrl'])->toBe('/live/shoutbox/messages');
});

it('shows no card and no room while community is switched off for the brand', function (): void {
    $brand = $this->withBrand();

    $eventId = (string) Str::ulid();
    $ada = shoutboxPageAdmitted($brand, $eventId, 'Ada');

    $keys = [];

    // Admitted, so the hub itself renders — with every card but this one.
    $this->actingAs($ada)->get('/live')->assertOk()->assertInertia(function (Assert $page) use (&$keys): void {
        /** @var list<array<string, mixed>> $rendered */
        $rendered = $page->toArray()['props']['hub']['cards'];
        $keys = array_column($rendered, 'key');
    });

    $this->actingAs($ada)->get('/live/shoutbox')->assertNotFound();

    expect($keys)->not->toContain('community.shoutbox')
        ->and(Room::withoutBrandScope()->count())->toBe(0);
});

/**
 * The reconciliation a client runs when its socket comes back. The removal
 * must come back in exactly the broadcast's tombstone shape — `id`,
 * `roomId`, `removedAt` — and never with the reason, the organizer, the body
 * or the author, or the catch-up path would leak what the broadcast
 * withholds.
 */
it('replays everything after a cursor, a removed line as nothing but the broadcast tombstone', function (): void {
    $brand = $this->withBrand();
    $this->activateModule('community', $brand);

    $eventId = (string) Str::ulid();
    $ada = shoutboxPageAdmitted($brand, $eventId, 'Ada');
    $bob = shoutboxPageAdmitted($brand, $eventId, 'Bob');

    $this->actingAs($ada)->get('/live/shoutbox')->assertOk();
    $room = Room::withoutBrandScope()->sole();

    $post = app(PostMessage::class);
    $cursor = $post->handle($room, $ada->id, new PostMessageData('Erste Zeile'));
    $kept = $post->handle($room, $bob->id, new PostMessageData('Zweite Zeile'));
    $removed = $post->handle($room, $bob->id, new PostMessageData('Etwas Beleidigendes'));

    $organizerId = (string) Str::ulid();
    app(RemoveMessage::class)->handle($removed, $organizerId, 'Insult');

    $feed = $this->actingAs($ada)
        ->getJson('/live/shoutbox/messages?after='.$cursor->id)
        ->assertOk()
        ->json();

    $kept->refresh();
    $removed->refresh();

    expect($feed['hasMore'])->toBeFalse()
        ->and($feed['lines'])->toHaveCount(2)
        ->and($feed['lines'][0])->toBe([
            'id' => $kept->id,
            'roomId' => $room->id,
            'authorUserId' => $bob->id,
            'authorDisplayName' => 'Bob',
            'body' => 'Zweite Zeile',
            'postedAt' => $kept->created_at?->toIso8601String(),
        ])
        ->and($feed['lines'][1])->toBe([
            'id' => $removed->id,
            'roomId' => $room->id,
            'removedAt' => $removed->deleted_at?->toIso8601String(),
        ])
        ->and(json_encode($feed))->not->toContain('Insult')
        ->and(json_encode($feed))->not->toContain($organizerId)
        ->and(json_encode($feed))->not->toContain('Etwas Beleidigendes');

    // Upward: the one line before the second.
    $this->actingAs($ada)
        ->getJson('/live/shoutbox/messages?before='.$kept->id)
        ->assertOk()
        ->assertJsonPath('hasMore', false)
        ->assertJsonCount(1, 'lines')
        ->assertJsonPath('lines.0.id', $cursor->id);
});

/**
 * The gap an id cursor cannot see: a line the client already held, removed
 * while its socket was down. The previous answer's `syncedAt` comes back as
 * `since`, and the removal returns in exactly the broadcast's tombstone
 * shape. A removal from before `since` — which the client already applied —
 * is not sent again.
 */
it('replays the removal of a line the client already held, since its last answer', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-17 20:00:00Z'));

    $brand = $this->withBrand();
    $this->activateModule('community', $brand);

    $eventId = (string) Str::ulid();
    $ada = shoutboxPageAdmitted($brand, $eventId, 'Ada');

    $this->actingAs($ada)->get('/live/shoutbox')->assertOk();
    $room = Room::withoutBrandScope()->sole();

    $post = app(PostMessage::class);
    $older = $post->handle($room, $ada->id, new PostMessageData('Schon vorher entfernt'));
    $held = $post->handle($room, $ada->id, new PostMessageData('Wird entfernt, während der Socket weg ist'));

    app(RemoveMessage::class)->handle($older, (string) Str::ulid(), 'Früher');

    // The client's last answer before its socket dropped.
    $this->travelTo(CarbonImmutable::parse('2026-10-17 20:00:10Z'));

    $syncedAt = $this->actingAs($ada)
        ->getJson('/live/shoutbox/messages?'.http_build_query(['after' => $held->id]))
        ->assertOk()
        ->assertJsonPath('removed', [])
        ->json('syncedAt');

    expect($syncedAt)->toBe('2026-10-17T20:00:10+00:00');

    // Removed while the client was away.
    $this->travelTo(CarbonImmutable::parse('2026-10-17 20:00:20Z'));
    $organizerId = (string) Str::ulid();
    app(RemoveMessage::class)->handle($held, $organizerId, 'Insult');

    $this->travelTo(CarbonImmutable::parse('2026-10-17 20:00:30Z'));

    $feed = $this->actingAs($ada)
        ->getJson('/live/shoutbox/messages?'.http_build_query(['after' => $held->id, 'since' => $syncedAt]))
        ->assertOk()
        ->json();

    $held->refresh();

    expect($feed['lines'])->toBe([])
        ->and($feed['syncedAt'])->toBe('2026-10-17T20:00:30+00:00')
        ->and($feed['removed'])->toBe([[
            'id' => $held->id,
            'roomId' => $room->id,
            'removedAt' => $held->deleted_at?->toIso8601String(),
        ]])
        ->and(json_encode($feed))->not->toContain('Insult')
        ->and(json_encode($feed))->not->toContain($organizerId)
        ->and(json_encode($feed))->not->toContain('Wird entfernt');
});

it('tells a muted participant the reason and the remaining time, before and when they post', function (): void {
    $this->freezeTime();

    $brand = $this->withBrand();
    $this->activateModule('community', $brand);

    $eventId = (string) Str::ulid();
    $ada = shoutboxPageAdmitted($brand, $eventId, 'Ada');

    $this->actingAs($ada)->get('/live/shoutbox')->assertOk();
    $room = Room::withoutBrandScope()->sole();

    app(MuteParticipant::class)->handle($room, $ada->id, (string) Str::ulid(), 'Spam', 10);

    $this->actingAs($ada)
        ->get('/live/shoutbox')
        ->assertInertia(fn (Assert $page) => $page
            ->where('room.wall.mute', 'Du kannst 10 Minuten nicht schreiben: Spam')
            ->where('room.wall.retryAfterSeconds', 600));

    // The Action's refusal, on the field it names — the controller never
    // reads the sentence, it only moves it.
    $this->actingAs($ada)
        ->postJson('/live/shoutbox/messages', ['body' => 'Hallo'])
        ->assertUnprocessable()
        ->assertJsonPath('errors.body.0', 'Du kannst 10 Minuten nicht schreiben: Spam')
        ->assertJsonPath('cause', 'muted')
        ->assertJsonPath('retryAfterSeconds', 600);

    expect(Message::withoutBrandScope()->count())->toBe(0);
});

it('shows a locked wall as readable and refuses a post to it', function (): void {
    $brand = $this->withBrand();
    $this->activateModule('community', $brand);

    $eventId = (string) Str::ulid();
    $ada = shoutboxPageAdmitted($brand, $eventId, 'Ada');

    $this->actingAs($ada)->postJson('/live/shoutbox/messages', ['body' => 'Vor dem Sperren'])->assertCreated();
    app(LockRoom::class)->handle(Room::withoutBrandScope()->sole(), (string) Str::ulid());

    $this->actingAs($ada)
        ->get('/live/shoutbox')
        ->assertInertia(fn (Assert $page) => $page
            ->has('room.wall.lines', 1)
            ->where('room.wall.notice', 'Die Wall ist gerade gesperrt. Du kannst im Moment nichts schreiben.'));

    $this->actingAs($ada)
        ->postJson('/live/shoutbox/messages', ['body' => 'Nach dem Sperren'])
        ->assertUnprocessable()
        ->assertJsonPath('cause', 'room_locked')
        ->assertJsonPath('retryAfterSeconds', null);

    expect(Message::withoutBrandScope()->count())->toBe(1);
});

/**
 * The route's coarse half of D-COM-09 refuses the sixth post in ten seconds
 * before the Action sees it — and answers with the Action's own German
 * refusal, so the compose box reads one shape whichever half said no.
 */
it('refuses the sixth post in ten seconds with the same German refusal and a countdown', function (): void {
    $this->freezeTime();

    $brand = $this->withBrand();
    $this->activateModule('community', $brand);

    $eventId = (string) Str::ulid();
    $ada = shoutboxPageAdmitted($brand, $eventId, 'Ada');

    foreach (range(1, PostMessage::MAX_PER_WINDOW) as $number) {
        $this->actingAs($ada)->postJson('/live/shoutbox/messages', ['body' => "Zeile {$number}"])->assertCreated();
    }

    $this->actingAs($ada)
        ->postJson('/live/shoutbox/messages', ['body' => 'Eine zu viel'])
        ->assertStatus(429)
        ->assertJsonPath('errors.body.0', 'Du schreibst zu schnell. Versuch es in 10 Sekunden noch einmal.')
        ->assertJsonPath('cause', 'too_many_messages')
        ->assertJsonPath('retryAfterSeconds', PostMessage::WINDOW_SECONDS);

    expect(Message::withoutBrandScope()->count())->toBe(PostMessage::MAX_PER_WINDOW);
});

it('files a report once per participant and enforces the note\'s length at the form', function (): void {
    $brand = $this->withBrand();
    $this->activateModule('community', $brand);

    $eventId = (string) Str::ulid();
    $ada = shoutboxPageAdmitted($brand, $eventId, 'Ada');
    $bob = shoutboxPageAdmitted($brand, $eventId, 'Bob');

    $this->actingAs($bob)->postJson('/live/shoutbox/messages', ['body' => 'Unfug'])->assertCreated();
    $line = Message::withoutBrandScope()->sole();

    // A line of some other room is not this room's to report.
    $elsewhere = Message::factory()->create(['room_id' => Room::factory()->create()->id]);
    $this->actingAs($ada)->postJson("/live/shoutbox/messages/{$elsewhere->id}/report")->assertNotFound();

    $this->actingAs($ada)
        ->postJson("/live/shoutbox/messages/{$line->id}/report", ['note' => str_repeat('x', ReportData::MAX_NOTE_LENGTH + 1)])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('note');

    expect(MessageReport::withoutBrandScope()->count())->toBe(0);

    $this->actingAs($ada)->postJson("/live/shoutbox/messages/{$line->id}/report", ['note' => 'Beleidigend'])->assertNoContent();
    $this->actingAs($ada)->postJson("/live/shoutbox/messages/{$line->id}/report")->assertNoContent();

    $report = MessageReport::withoutBrandScope()->sole();

    expect($report->message_id)->toBe($line->id)
        ->and($report->reporter_user_id)->toBe($ada->id)
        ->and($report->note)->toBe('Beleidigend');
});

it('records the heartbeat and answers with who is here, the badge beside the dot', function (): void {
    if (! shoutboxPageRedisReachable()) {
        $this->markTestSkipped('Redis is not reachable from this test process.');
    }

    $brand = $this->withBrand();
    $this->activateModule('community', $brand);

    $eventId = (string) Str::ulid();
    $ada = shoutboxPageAdmitted($brand, $eventId, 'Ada');
    $bob = shoutboxPageAdmitted($brand, $eventId, 'Bob');

    try {
        // Ada is here and online; Bob was checked in at the door and has not
        // opened the room — "Checked-In + Offline", a badge with no dot.
        $this->actingAs($ada)
            ->postJson('/live/shoutbox/presence', ['status' => 'online'])
            ->assertOk()
            ->assertExactJson([
                ['userId' => $ada->id, 'displayName' => 'Ada', 'status' => 'online', 'checkedIn' => true],
                ['userId' => $bob->id, 'displayName' => 'Bob', 'status' => 'offline', 'checkedIn' => true],
            ]);

        $room = Room::withoutBrandScope()->sole();

        expect(app(ConnectionState::class)->forRoom($room))->toHaveKey($ada->id);

        $this->actingAs($ada)->deleteJson('/live/shoutbox/presence')->assertNoContent();

        expect(app(ConnectionState::class)->forRoom($room))->not->toHaveKey($ada->id);
    } finally {
        $room ??= Room::withoutBrandScope()->first();

        Redis::connection()->del(ConnectionState::connectionKey($brand->getKey(), $ada->id));

        if ($room instanceof Room) {
            Redis::connection()->del(ConnectionState::roomKey($brand->getKey(), $room->id));
        }
    }
});
