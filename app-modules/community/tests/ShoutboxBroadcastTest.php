<?php

declare(strict_types=1);

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Broadcasting\BroadcastException;
use Illuminate\Contracts\Broadcasting\Broadcaster;
use Illuminate\Database\Events\QueryExecuted;
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
use Konventix\Community\Events\ShoutboxMessageRemoved;
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
 * 3. **Connection state touches no table at all.** Asserted three ways at
 *    once, because each one alone has a blind spot: the query log (an
 *    `UPDATE` to a column on an existing row moves no row count), a row
 *    census of every table in the schema (a write through a second
 *    connection or a table appearing mid-test), and the cache store pointed
 *    at the database for the length of the window (`phpunit.xml` sets
 *    `CACHE_STORE=array`, so a `Cache::put()` reimplementation — the most
 *    plausible way ADR-0067 decision 3 gets broken by accident — would
 *    otherwise write nowhere and pass everything).
 *
 * Helper and class names are prefixed `shoutboxBroadcast*` — Pest helpers
 * and classes declared in a test file are process-global across every
 * module's suite (`.ai/rules/tests.md`).
 */
final class ShoutboxBroadcastSpyBroadcaster implements Broadcaster
{
    /** @var list<array{channels: list<string>, event: string, payload: array<string, mixed>}> */
    public array $sent = [];

    /** Stands in for Reverb being down — what `BroadcastToRoom` has to absorb. */
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
 * `BROADCAST_CONNECTION=null`, so without this a shoutbox broadcast
 * lands in `NullBroadcaster` and proves nothing.
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
 * table holding connection state.
 *
 * What this alone proves is narrow and worth being exact about: no `INSERT`
 * into any table between the two calls, and no table appearing in the
 * schema mid-test. It says nothing about an `UPDATE` to a column on a row
 * that already exists — that is what `shoutboxBroadcastWrites()` is for —
 * and nothing about a cache write unless the cache store is pointed at the
 * database first, which the test that uses it does.
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
 * The write statements among a recorded query log. `INSERT`, `UPDATE` and
 * `DELETE` are the three shapes ADR-0067 decision 3 forbids, and the middle
 * one is the one a row census cannot see: writing connection state into
 * `community_members.last_seen_at` moves no count anywhere.
 *
 * Reads are deliberately allowed through — `enterRoom()` authorises, and
 * authorising means reading the room, the module's activation and the
 * admission projection.
 *
 * @param  list<string>  $queries
 * @return list<string>
 */
function shoutboxBroadcastWrites(array $queries): array
{
    return array_values(array_filter(
        $queries,
        fn (string $sql): bool => preg_match('/^\s*(insert|update|delete|truncate|copy)\b/i', $sql) === 1,
    ));
}

/**
 * Every declared property of an event object, whatever its visibility, and
 * in declaration order. Public-only would let a `private string $body` slip
 * past the pin below — unreachable from `$event->…`, but the claim these
 * tests make is "the domain payload cannot carry text", not "cannot carry
 * it publicly".
 *
 * @return array<string, mixed>
 */
function shoutboxBroadcastProperties(object $event): array
{
    $properties = [];

    foreach ((new ReflectionClass($event))->getProperties() as $property) {
        $properties[$property->getName()] = $property->getValue($event);
    }

    return $properties;
}

/**
 * The constructor's parameter names, so that the pin catches a widening
 * that somehow did not land as a property too.
 *
 * @param  class-string  $class
 * @return list<string>
 */
function shoutboxBroadcastConstructorParameters(string $class): array
{
    $constructor = (new ReflectionClass($class))->getConstructor();

    return array_map(
        fn (ReflectionParameter $parameter): string => $parameter->getName(),
        $constructor?->getParameters() ?? [],
    );
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
// wall would carry it twice. `BroadcastToRoom` is what stops that — the
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

// I2: the broadcaster hands `join()` the user IT authenticated, while
// `ActiveEventContext` answers for whoever `auth()` resolves. Those are the
// same person only because `routes/channels.php` registers the pattern with
// no `guards` option — a configuration detail, not a guarantee. The
// discriminator is deliberately the way round that FAILS without the check:
// the ambient viewer is admitted and the handed-in user is not, so a channel
// trusting the ambient answer would hand a stranger the room.
it('refuses a subscriber the broadcaster authenticated when somebody else is the ambient viewer', function (): void {
    $brand = $this->withBrand();
    $this->activateModule('community', $brand);

    $eventId = (string) Str::ulid();
    $room = Room::factory()->create(['subject_id' => $eventId]);

    $admitted = User::factory()->create();
    event(hubAdmitted($brand->getKey(), $admitted->id, $eventId));
    $this->actingAs($admitted);

    // Nobody admitted this account to anything. Under a `guards` option it
    // is what `Broadcaster::retrieveUser()` could hand in while the session
    // cookie still authenticates the admitted one.
    $imposter = User::factory()->create();

    $channel = new RoomChannel;

    expect($channel->join($imposter, $room->id))->toBeFalse()
        // The control: the same room, the same ambient viewer, asked about
        // the person who really is signed in.
        ->and($channel->join($admitted, $room->id))->toBeTrue();
});

// The heart of the §9 tension: the same class is both a module contract and
// a browser message, and only the second half may carry what was typed.
// BOTH events are pinned — a `public string $body` added to the removal
// would otherwise put a removed participant's text on the module's public
// contract with every test in this file still green (M2) — and the pin
// enumerates every visibility, not only public ones (M3).
it('hands a PHP listener ULIDs and scalars only, and the body to the browser alone', function (): void {
    $this->withBrand();

    $body = 'Wo ist die Schlange für den Lötkurs?';
    $room = Room::factory()->create();
    $posted = null;
    $removed = null;

    Event::listen(ShoutboxMessagePosted::class, function (ShoutboxMessagePosted $event) use (&$posted): void {
        $posted = $event;
    });

    Event::listen(ShoutboxMessageRemoved::class, function (ShoutboxMessageRemoved $event) use (&$removed): void {
        $removed = $event;
    });

    $message = app(PostMessage::class)->handle($room, (string) Str::ulid(), new PostMessageData($body));
    app(RemoveMessage::class)->handle($message, (string) Str::ulid(), 'Spam');

    expect($posted)->toBeInstanceOf(ShoutboxMessagePosted::class)
        ->and($removed)->toBeInstanceOf(ShoutboxMessageRemoved::class);

    $postedProperties = shoutboxBroadcastProperties($posted);
    $removedProperties = shoutboxBroadcastProperties($removed);

    expect(array_keys($postedProperties))->toBe(['messageId', 'roomId', 'brandId', 'eventId', 'authorUserId', 'postedAt'])
        ->and(shoutboxBroadcastConstructorParameters(ShoutboxMessagePosted::class))
        ->toBe(['messageId', 'roomId', 'brandId', 'eventId', 'authorUserId', 'postedAt'])
        ->and($postedProperties)->each->toBeString()
        ->and(implode('|', $postedProperties))->not->toContain($body)
        // ...and the wire payload, the one place it is allowed to be.
        ->and($posted->broadcastWith()['body'])->toBe($body);

    expect(array_keys($removedProperties))->toBe(['messageId', 'roomId', 'brandId', 'removedByUserId', 'reason', 'removedAt'])
        ->and(shoutboxBroadcastConstructorParameters(ShoutboxMessageRemoved::class))
        ->toBe(['messageId', 'roomId', 'brandId', 'removedByUserId', 'reason', 'removedAt'])
        ->and($removedProperties)->each->toBeString()
        ->and(implode('|', $removedProperties))->not->toContain($body)
        // An exact compare, not `toHaveKeys`: the tombstone's wire payload
        // is the one place in this module where the wire carries LESS than
        // the domain event, so an addition to it has to fail here.
        ->and($removed->broadcastWith())->toBe([
            'id' => $message->id,
            'roomId' => $room->id,
            'removedAt' => $removed->removedAt,
        ]);
});

// M5: claiming presence in a room is a claim about a room one has to be
// allowed into, so `enterRoom()` runs the same four checks the channel
// subscription runs and refuses rather than writing. Task 5's heartbeat
// route inherits that whatever middleware it ends up carrying.
it('refuses to record presence for an account the room would not admit', function (): void {
    $brand = $this->withBrand();
    $this->activateModule('community', $brand);

    if (! shoutboxBroadcastRedisReachable()) {
        $this->markTestSkipped('Redis unreachable');
    }

    $eventId = (string) Str::ulid();
    $room = Room::factory()->create(['subject_id' => $eventId]);

    $stranger = User::factory()->create();
    $this->actingAs($stranger);

    $state = app(ConnectionState::class);

    expect(function () use ($state, $room, $stranger): void {
        $state->enterRoom($room, $stranger);
    })->toThrow(AuthorizationException::class)
        // Refused means nothing was written, not "written and hidden".
        ->and($state->forRoom($room))->toBe([]);

    Redis::connection()->del(ConnectionState::roomKey($brand->getKey(), $room->id));
});

// ADR-0067 decision 3, proved three ways because each one alone has a hole:
// the query log catches an UPDATE to a column the census cannot see, the
// census catches a write the query log would miss, and pointing the cache
// store at the database for the length of the window is what makes the
// `Cache::put()` back door visible at all under `CACHE_STORE=array`.
it('writes connection state to Redis and to no table at all', function (): void {
    $brand = $this->withBrand();
    $this->activateModule('community', $brand);

    if (! shoutboxBroadcastRedisReachable()) {
        $this->markTestSkipped('Redis unreachable');
    }

    $eventId = (string) Str::ulid();
    $room = Room::factory()->create(['subject_id' => $eventId]);

    $user = User::factory()->create();
    event(hubAdmitted($brand->getKey(), $user->id, $eventId));
    $this->actingAs($user);

    $state = app(ConnectionState::class);
    $brandId = $brand->getKey();

    // One authorised round trip BEFORE the window opens, so that everything
    // the four checks legitimately write — the Pennant row, the memoised
    // admission projection, spatie's permission cache — is already warm and
    // the window contains only this class's own work.
    $state->enterRoom($room, $user);
    $state->leaveRoom($room, $user);

    // The back door, wired open. `phpunit.xml:40` sets CACHE_STORE=array, so
    // a reimplementation reaching for `Cache::put('community:connection:…')`
    // would write nowhere and pass a row census untouched. Pointed at the
    // database store it writes a `cache` row instead, which both the query
    // log and the census below refuse.
    config(['cache.default' => 'database']);

    $before = shoutboxBroadcastTableCensus();

    /** @var list<string> $queries */
    $queries = [];

    DB::listen(function (QueryExecuted $query) use (&$queries): void {
        $queries[] = $query->sql;
    });

    $state->online($brandId, $user);
    $state->enterRoom($room, $user);

    expect($state->forRoom($room))->toBe([$user->id => ConnectionStatus::Online])
        ->and($state->statusFor($brandId, $user->id))->toBe(ConnectionStatus::Online);

    $state->away($brandId, $user);

    expect($state->forRoom($room))->toBe([$user->id => ConnectionStatus::Away]);

    // A heartbeat that stopped decays to Away on its own, with no sweep and
    // nothing written: the read derives it from the instant in the key.
    $state->online($brandId, $user);
    $this->travel(ConnectionState::ONLINE_SECONDS + 1)->seconds();

    expect($state->forRoom($room))->toBe([$user->id => ConnectionStatus::Away]);

    $state->leaveRoom($room, $user);

    expect($state->forRoom($room))->toBe([]);

    // Snapshotted here: the closing census queries every table itself.
    $executed = $queries;

    $this->travelBack();
    config(['cache.default' => 'array']);

    Redis::connection()->del(
        ConnectionState::connectionKey($brandId, $user->id),
        ConnectionState::roomKey($brandId, $room->id),
    );

    expect(shoutboxBroadcastWrites($executed))->toBe([])
        ->and(shoutboxBroadcastTableCensus())->toBe($before)
        ->and(Schema::hasTable('community_connection_state'))->toBeFalse()
        ->and(Schema::hasTable('community_connections'))->toBeFalse()
        ->and(Schema::hasTable('community_room_presence'))->toBeFalse()
        ->and(Schema::hasTable('community_online_users'))->toBeFalse();
});
