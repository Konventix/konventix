<?php

declare(strict_types=1);

use Illuminate\Broadcasting\BroadcastException;
use Illuminate\Contracts\Broadcasting\Broadcaster;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Konventix\Community\Actions\PostMessage;
use Konventix\Community\Actions\RemoveMessage;
use Konventix\Community\Broadcasting\RoomChannel;
use Konventix\Community\Data\PostMessageData;
use Konventix\Community\Enums\ConnectionStatus;
use Konventix\Community\Enums\Permission;
use Konventix\Community\Events\ShoutboxMessagePosted;
use Konventix\Community\Models\EventPresence;
use Konventix\Community\Models\Message;
use Konventix\Community\Models\Room;
use Konventix\Community\Presence\ConnectionState;
use Konventix\Identity\Models\User;
use Konventix\Platform\Brands\BrandContext;
use Konventix\Platform\Models\Permission as PermissionModel;
use Konventix\Platform\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * The shoutbox's realtime half (design §5 and §8, ADR-0067): the private
 * per-room channel, what travels over it, and the connection state that is
 * never written down.
 *
 * Three things these tests exist to hold:
 *
 * 1. **The channel gates on Admission's answer.** Authorisation goes
 *    through Platform's `ActiveEventContext` and never through Community's
 *    own `community_event_presence` badge (ADR-0067 decision 2) — the
 *    moderator case asserts that table is empty while the channel opens, so
 *    a future gate accidentally reading the badge cannot pass this file.
 * 2. **The domain payload and the wire payload are two things.** A PHP
 *    listener receives ULIDs and scalars (spec §9); the body exists only
 *    inside `broadcastWith()`'s return. One test reads the event's public
 *    properties by reflection and asserts the body is in none of them.
 * 3. **Connection state touches no table at all.** Asserted by counting
 *    every row of every table in the schema before and after, not by
 *    failing to look for a model.
 *
 * Helper and class names are prefixed `shoutboxBroadcast*` — Pest helpers
 * and classes declared in a test file are process-global across every
 * module's suite (`.ai/rules/tests.md`).
 */
final class ShoutboxBroadcastSpyBroadcaster implements Broadcaster
{
    /** @var list<array{channels: list<string>, event: string, payload: array<string, mixed>}> */
    public array $sent = [];

    /** Stands in for Reverb being down — what `ShouldRescue` has to absorb. */
    public bool $throws = false;

    public function auth($request)
    {
        return null;
    }

    public function validAuthenticationResponse($request, $result)
    {
        return null;
    }

    /**
     * `BroadcastEvent` hands a broadcaster the `Channel` **objects** and
     * leaves the formatting to it (`PusherBroadcaster::formatChannels()`
     * does the same), so the `private-` prefix only appears once they are
     * cast to strings here. That cast is the assertion's whole point: it is
     * what proves the channel is private and per-room.
     */
    public function broadcast(array $channels, $event, array $payload = []): void
    {
        if ($this->throws) {
            throw new BroadcastException('Reverb is unreachable.');
        }

        $this->sent[] = [
            'channels' => array_map(fn ($channel): string => (string) $channel, $channels),
            'event' => (string) $event,
            'payload' => $payload,
        ];
    }
}

/**
 * Swaps the default broadcast connection for a spy. `phpunit.xml` sets
 * `BROADCAST_CONNECTION=null`, so without this a `ShouldBroadcastNow` event
 * broadcasts into `NullBroadcaster` and proves nothing.
 */
function shoutboxBroadcastSpy(bool $throws = false): ShoutboxBroadcastSpyBroadcaster
{
    $spy = new ShoutboxBroadcastSpyBroadcaster;
    $spy->throws = $throws;

    Broadcast::extend('shoutbox-spy', function () use ($spy): ShoutboxBroadcastSpyBroadcaster {
        return $spy;
    });

    config([
        'broadcasting.connections.shoutbox-spy' => ['driver' => 'shoutbox-spy'],
        'broadcasting.default' => 'shoutbox-spy',
    ]);

    return $spy;
}

/**
 * A user holding `community.moderate-community` on the brand, granted the
 * way `permissions:sync` materialises it — a brand role plus a
 * `model_has_roles` row (`seatingUserWithBookOnBehalf()` is the precedent).
 */
function shoutboxBroadcastModerator(string $brandId): User
{
    $user = User::factory()->create();

    PermissionModel::query()->firstOrCreate(['name' => Permission::ModerateCommunity->value, 'guard_name' => 'web']);

    $role = Role::query()->create([
        'name' => 'Shoutbox desk '.Str::lower(Str::random(4)),
        'guard_name' => 'web',
        'brand_id' => $brandId,
    ]);
    $role->givePermissionTo([Permission::ModerateCommunity->value]);

    DB::table('model_has_roles')->insert([
        'role_id' => $role->id,
        'model_type' => $user->getMorphClass(),
        'model_id' => $user->id,
        'brand_id' => $brandId,
    ]);

    return $user;
}

/**
 * Every row of every table in the test schema. The point of counting all of
 * them rather than naming one is that ADR-0067 decision 3 forbids *any*
 * table holding connection state — including a cache table, which is how
 * this rule would most plausibly be broken by accident.
 *
 * @return array<string, int>
 */
function shoutboxBroadcastTableCensus(): array
{
    $census = [];

    foreach (DB::select('select tablename from pg_tables where schemaname = current_schema()') as $table) {
        $name = (string) $table->tablename;
        $census[$name] = DB::table($name)->count();
    }

    ksort($census);

    return $census;
}

/**
 * Skips rather than failing the suite on an infrastructure gap — but Redis
 * IS reachable in the dev container and CI's `test:php` job runs a
 * `redis:7-alpine` service beside it (`.gitlab-ci.yml`), so this must
 * actually run in both, not skip.
 */
function shoutboxBroadcastRedisReachable(): bool
{
    try {
        Redis::connection()->ping();

        return true;
    } catch (Throwable) {
        return false;
    }
}

it('refuses a member who is not admitted to the room\'s event, and admits one who is', function (): void {
    $brand = $this->withBrand();
    $this->activateModule('community', $brand);

    $eventId = (string) Str::ulid();
    $room = Room::factory()->create(['subject_id' => $eventId]);
    $channel = new RoomChannel;

    // Nobody admitted them to anything.
    $stranger = User::factory()->create();
    $this->actingAs($stranger);
    expect($channel->join($stranger, $room->id))->toBeFalse();

    // Admitted — to a different event on the same brand. The room's subject
    // is the gate, not "is admitted to something".
    $elsewhere = User::factory()->create();
    event(hubAdmitted($brand->getKey(), $elsewhere->id, (string) Str::ulid()));
    $this->actingAs($elsewhere);
    expect($channel->join($elsewhere, $room->id))->toBeFalse();

    $admitted = User::factory()->create();
    event(hubAdmitted($brand->getKey(), $admitted->id, $eventId));
    $this->actingAs($admitted);
    expect($channel->join($admitted, $room->id))->toBeTrue();
});

// D-COM-04, spec §6: "the room's audience and its moderators are
// deliberately different sets". The badge assertion is the ADR-0067
// decision-2 guard — the channel opens for a desk that `community_event_presence`
// has never heard of, so nothing on this path can be reading it.
it('admits a moderator who was never scanned in, without consulting the badge', function (): void {
    $brand = $this->withBrand();
    $this->activateModule('community', $brand);

    $room = Room::factory()->create();
    $moderator = shoutboxBroadcastModerator($brand->getKey());

    // `ResolveBrand` sets the permissions team on a real request; nothing
    // did here, and a brand role is invisible without it.
    app(PermissionRegistrar::class)->setPermissionsTeamId($brand->getKey());
    $this->actingAs($moderator);

    $opened = (new RoomChannel)->join($moderator, $room->id);

    app(PermissionRegistrar::class)->setPermissionsTeamId(null);

    expect($opened)->toBeTrue()
        ->and(EventPresence::withoutBrandScope()->count())->toBe(0);
});

it('refuses an invented room id, and a room whose brand this request did not resolve', function (): void {
    $brand = $this->withBrand();
    $this->activateModule('community', $brand);

    $eventId = (string) Str::ulid();
    $room = Room::factory()->create(['subject_id' => $eventId]);

    $admitted = User::factory()->create();
    event(hubAdmitted($brand->getKey(), $admitted->id, $eventId));
    $this->actingAs($admitted);

    $channel = new RoomChannel;

    expect($channel->join($admitted, (string) Str::ulid()))->toBeFalse();

    // The same person and the same room, with no brand in context: both the
    // capability and the admission answer for whichever brand the request
    // resolved, so an unresolved one can only mean no.
    app(BrandContext::class)->forget();

    expect($channel->join($admitted, $room->id))->toBeFalse();
});

it('refuses every subscriber while the module is switched off for the brand', function (): void {
    $brand = $this->withBrand();

    $eventId = (string) Str::ulid();
    $room = Room::factory()->create(['subject_id' => $eventId]);

    $admitted = User::factory()->create();
    event(hubAdmitted($brand->getKey(), $admitted->id, $eventId));
    $this->actingAs($admitted);

    // `/broadcasting/auth` carries no `ensure-module` middleware, so the
    // channel is the only place this question can be asked (ADR-0003).
    expect((new RoomChannel)->join($admitted, $room->id))->toBeFalse();
});

it('broadcasts a posted line exactly once, on the room\'s own private channel', function (): void {
    $this->withBrand();
    $spy = shoutboxBroadcastSpy();

    $room = Room::factory()->create();
    $author = (string) Str::ulid();

    $message = app(PostMessage::class)->handle($room, $author, new PostMessageData('Der Lötkurs beginnt gleich.'));

    expect($spy->sent)->toHaveCount(1)
        ->and($spy->sent[0]['channels'])->toBe(['private-'.RoomChannel::nameFor($room->id)])
        ->and($spy->sent[0]['event'])->toBe('shoutbox.message.posted')
        ->and($spy->sent[0]['payload']['id'])->toBe($message->id)
        ->and($spy->sent[0]['payload']['authorUserId'])->toBe($author)
        // The body is on the wire and nowhere else — this is the half of
        // the §9 tension that broadcastWith() is allowed to carry.
        ->and($spy->sent[0]['payload']['body'])->toBe('Der Lötkurs beginnt gleich.');
});

// Spec §4.2: a tombstone "carries neither body nor author to other
// participants", and the organizer's reason is the desk's, not the room's.
// The wire payload is therefore NARROWER than the domain payload, which
// keeps both `reason` and `removedByUserId`.
it('broadcasts a removal as a bare tombstone, without the reason or the organizer', function (): void {
    $this->withBrand();

    $room = Room::factory()->create();
    $message = app(PostMessage::class)->handle($room, (string) Str::ulid(), new PostMessageData('Bitte entfernen.'));

    // Installed after the post, so only the removal can land in it.
    $spy = shoutboxBroadcastSpy();
    $moderatorId = (string) Str::ulid();

    app(RemoveMessage::class)->handle($message, $moderatorId, 'Spam');

    $payload = json_encode($spy->sent[0]['payload'] ?? []);

    expect($spy->sent)->toHaveCount(1)
        ->and($spy->sent[0]['event'])->toBe('shoutbox.message.removed')
        ->and($spy->sent[0]['channels'])->toBe(['private-'.RoomChannel::nameFor($room->id)])
        ->and($spy->sent[0]['payload'])->toHaveKeys(['id', 'roomId', 'removedAt'])
        ->and($spy->sent[0]['payload'])->not->toHaveKey('reason')
        ->and($spy->sent[0]['payload'])->not->toHaveKey('body')
        ->and($payload)->not->toContain('Spam')
        ->and($payload)->not->toContain($moderatorId)
        ->and($payload)->not->toContain('Bitte entfernen.');
});

// The broadcast leaves *after* the commit (`PostMessage`), so by the time
// Reverb is asked the row is durable: a throw here would refuse a line the
// database has already accepted, the participant would retype it, and the
// wall would carry it twice. `ShouldRescue` is what stops that — the
// failure is reported, not raised.
it('keeps a committed line committed when the broadcast itself fails', function (): void {
    $this->withBrand();
    Exceptions::fake();
    shoutboxBroadcastSpy(throws: true);

    $room = Room::factory()->create();
    $author = (string) Str::ulid();

    $message = app(PostMessage::class)->handle($room, $author, new PostMessageData('Reverb ist gerade weg.'));

    expect($message->exists)->toBeTrue()
        ->and(Message::query()->where('room_id', $room->id)->count())->toBe(1)
        ->and(Message::query()->find($message->id)?->body)->toBe('Reverb ist gerade weg.');

    // Swallowed for the caller, but never silent: the operator still finds
    // it, which is the difference between rescue() and an empty catch.
    Exceptions::assertReported(BroadcastException::class);
});

// The heart of the §9 tension: the same class is both a module contract and
// a browser message, and only the second half may carry what was typed.
it('hands a PHP listener ULIDs and scalars only, and the body to the browser alone', function (): void {
    $this->withBrand();

    $body = 'Wo ist die Schlange für den Lötkurs?';
    $room = Room::factory()->create();
    $captured = null;

    Event::listen(ShoutboxMessagePosted::class, function (ShoutboxMessagePosted $event) use (&$captured): void {
        $captured = $event;
    });

    app(PostMessage::class)->handle($room, (string) Str::ulid(), new PostMessageData($body));

    expect($captured)->toBeInstanceOf(ShoutboxMessagePosted::class);

    $properties = [];

    foreach ((new ReflectionClass($captured))->getProperties(ReflectionProperty::IS_PUBLIC) as $property) {
        $properties[$property->getName()] = $property->getValue($captured);
    }

    expect(array_keys($properties))->toBe(['messageId', 'roomId', 'brandId', 'eventId', 'authorUserId', 'postedAt'])
        ->and($properties)->each->toBeString()
        ->and(implode('|', $properties))->not->toContain($body)
        // ...and the wire payload, the one place it is allowed to be.
        ->and($captured->broadcastWith()['body'])->toBe($body);
});

// ADR-0067 decision 3. The census is every table in the schema, counted
// before and after: a column, a new table or a database-backed cache entry
// would all move at least one of those numbers.
it('writes connection state to Redis and to no table at all', function (): void {
    $this->withBrand();

    if (! shoutboxBroadcastRedisReachable()) {
        $this->markTestSkipped('Redis unreachable');
    }

    $state = app(ConnectionState::class);
    $userId = (string) Str::ulid();
    $roomId = (string) Str::ulid();

    $before = shoutboxBroadcastTableCensus();

    $state->online($userId);
    $state->enterRoom($roomId, $userId);

    expect($state->forRoom($roomId))->toBe([$userId => ConnectionStatus::Online]);

    $state->away($userId);

    expect($state->forRoom($roomId))->toBe([$userId => ConnectionStatus::Away]);

    // A heartbeat that stopped decays to Away on its own, with no sweep and
    // nothing written: the read derives it from the instant in the key.
    $state->online($userId);
    $this->travel(ConnectionState::ONLINE_SECONDS + 1)->seconds();

    expect($state->forRoom($roomId))->toBe([$userId => ConnectionStatus::Away]);

    $this->travelBack();

    Redis::connection()->del(ConnectionState::connectionKey($userId), ConnectionState::roomKey($roomId));

    expect(shoutboxBroadcastTableCensus())->toBe($before)
        ->and(Schema::hasTable('community_connection_state'))->toBeFalse()
        ->and(Schema::hasTable('community_connections'))->toBeFalse()
        ->and(Schema::hasTable('community_room_presence'))->toBeFalse()
        ->and(Schema::hasTable('community_online_users'))->toBeFalse();
});
