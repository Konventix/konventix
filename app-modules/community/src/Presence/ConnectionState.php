<?php

declare(strict_types=1);

namespace Konventix\Community\Presence;

use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Redis\Connections\Connection;
use Illuminate\Support\Facades\Redis;
use InvalidArgumentException;
use Konventix\Community\Broadcasting\RoomChannel;
use Konventix\Community\Enums\ConnectionStatus;
use Konventix\Community\Models\Room;

/**
 * The **ephemeral** half of design §5: who is connected (Online/Away) and
 * who is active in a given room. Both axes live in Redis under a TTL and
 * are rebuilt from heartbeats.
 *
 * **Nothing here is ever written to a table, and that is a rule rather than
 * an optimisation** (ADR-0067 decision 3). Nothing about *when* a person
 * was at their keyboard goes to disk — which is what keeps decision 2
 * honest, since the only durable half is an admission badge Admission
 * already holds. So: no model, no migration, no column, and deliberately
 * not the cache either — a database-backed cache store would put exactly
 * this data in a `cache` table through the back door. `Redis::connection()`
 * is named directly for that reason.
 *
 * A Reverb restart loses both axes, correctly: they describe connections
 * that no longer exist. The badge survives, because the badge is somebody
 * else's fact.
 *
 * **Two thresholds, one key.** A heartbeat writes `status:unixSeconds`
 * under `TTL_SECONDS`, and the read derives all three states from it: the
 * key's own expiry is Offline (free, and the only way this data is ever
 * deleted), a heartbeat older than `ONLINE_SECONDS` reads as Away without
 * the client having to say so, and a client that knows its tab is hidden
 * calls `away()` to say so sooner. One write per heartbeat, no sweeps.
 *
 * **Every key carries the brand** (ADR-0010). No collision was possible
 * without it — both ids are ULIDs — but every other read in this module is
 * brand-scoped, and a per-brand read of the keyspace had nothing to filter
 * on. The brand is never taken from the ambient `BrandContext`: the room
 * axis reads it off the `Room` it was handed, and the connection axis takes
 * it as an argument, so nothing here depends on request state.
 *
 * **Who may write, and who may read.** A write says something about *this*
 * account, so every write takes the `Authenticatable` the request
 * authenticated and derives the id from it — an account id can never arrive
 * from a request body and claim somebody else's presence. `enterRoom()`
 * goes one step further and is **authorised**, not merely identified: it
 * puts the room through `RoomChannel::join()`, the same four checks the
 * channel subscription makes, and throws when they refuse. Task 5's
 * heartbeat route therefore cannot become an unauthenticated-by-design way
 * to insert an arbitrary account into an arbitrary brand's room presence
 * set, whatever middleware that route ends up carrying. The reads
 * (`statusFor()`, `forRoom()`) take plain ids and a `Room`, and are the
 * caller's to gate: they only ever disclose the connection axis of accounts
 * that are already in the room the caller named.
 *
 * Stateless by construction: the Redis connection is resolved per call and
 * nothing is kept on `$this`, so the scoped binding in
 * `CommunityServiceProvider` cannot carry one request's data into the next
 * under Octane.
 */
final class ConnectionState
{
    /**
     * How fresh a heartbeat has to be to still read as Online. Past this a
     * person reads as Away — a browser that stopped talking is not online,
     * whatever its last heartbeat claimed.
     */
    public const int ONLINE_SECONDS = 90;

    /**
     * How long any of this survives at all. Past it the key is gone and the
     * person reads as Offline, which is the whole of the deletion story.
     */
    public const int TTL_SECONDS = 300;

    /**
     * A heartbeat: this account has a live connection right now.
     */
    public function online(string $brandId, Authenticatable $user): void
    {
        $this->write($brandId, self::accountId($user), ConnectionStatus::Online);
    }

    /**
     * The client saying its tab is hidden, rather than the read deriving it
     * from a stale heartbeat. Same key, same TTL — only the status differs.
     */
    public function away(string $brandId, Authenticatable $user): void
    {
        $this->write($brandId, self::accountId($user), ConnectionStatus::Away);
    }

    public function statusFor(string $brandId, string $userId): ConnectionStatus
    {
        $value = $this->connection()->get(self::connectionKey($brandId, $userId));

        return $this->decode(is_string($value) ? $value : null);
    }

    /**
     * A heartbeat on the room axis: this account is looking at this room.
     * A sorted set scored by the instant, so a stale member is pruned by
     * score on the next read and the key itself expires when a room goes
     * quiet.
     *
     * **Authorised, not merely identified.** Claiming presence in a room is
     * a claim about a room one has to be allowed into, so this delegates to
     * `RoomChannel::join()` — the room exists, `community` is active for its
     * brand, the resolved brand is its brand, and the person holds either
     * the moderation capability or an admission to the room's event. One
     * decision, written once, so the presence axis and the channel cannot
     * drift apart into two different answers about the same room.
     *
     * @throws AuthorizationException when those four checks refuse
     */
    public function enterRoom(Room $room, Authenticatable $user): void
    {
        if (! app(RoomChannel::class)->join($user, $room->id)) {
            throw new AuthorizationException("This account may not enter room [{$room->id}].");
        }

        $redis = $this->connection();
        $key = self::roomKey($room->brand_id, $room->id);

        $redis->zadd($key, CarbonImmutable::now()->getTimestamp(), self::accountId($user));
        $redis->expire($key, self::TTL_SECONDS);
    }

    /**
     * Leaving needs no authorisation — it removes exactly the caller's own
     * member and can only ever narrow what the room discloses. It still
     * takes the authenticated user rather than an id, so that a request
     * cannot evict somebody else.
     */
    public function leaveRoom(Room $room, Authenticatable $user): void
    {
        $this->connection()->zrem(self::roomKey($room->brand_id, $room->id), self::accountId($user));
    }

    /**
     * Who is active in this room, and what their connection axis says —
     * the two ephemeral axes composed, which is the shape a presence list
     * renders (design §5). The durable Checked-In badge is not here: it is
     * `community_event_presence`'s, read by the surface and joined there,
     * so that nothing on this path can be mistaken for an access record.
     *
     * Someone whose connection key has expired is dropped rather than
     * listed as Offline: being active in a room means holding a connection,
     * so "in the room but offline" is not a state, it is a stale member.
     *
     * @return array<string, ConnectionStatus> account ULID => status
     */
    public function forRoom(Room $room): array
    {
        $redis = $this->connection();
        $key = self::roomKey($room->brand_id, $room->id);

        $redis->zremrangebyscore($key, '-inf', (string) (CarbonImmutable::now()->getTimestamp() - self::TTL_SECONDS));

        $members = $redis->zrange($key, 0, -1);

        if (! is_array($members)) {
            return [];
        }

        $state = [];

        foreach ($members as $member) {
            $userId = (string) $member;
            $status = $this->statusFor($room->brand_id, $userId);

            if ($status === ConnectionStatus::Offline) {
                continue;
            }

            $state[$userId] = $status;
        }

        return $state;
    }

    /**
     * Public so that a test can delete exactly what this class wrote
     * instead of spelling the key a second time — round-tripping a
     * `keys()` result into `del()` double-prefixes and deletes nothing
     * (`RedisWaitingRoomStoreTest`'s trap).
     */
    public static function connectionKey(string $brandId, string $userId): string
    {
        return "community:{$brandId}:connection:{$userId}";
    }

    public static function roomKey(string $brandId, string $roomId): string
    {
        return "community:{$brandId}:room:{$roomId}:active";
    }

    /**
     * The account ULID behind an authenticated user. `getAuthIdentifier()`
     * is `mixed` on the contract, so the one shape this application uses —
     * a scalar key — is asserted here rather than cast blindly into a
     * Redis key.
     */
    private static function accountId(Authenticatable $user): string
    {
        $id = $user->getAuthIdentifier();

        if (! is_scalar($id)) {
            throw new InvalidArgumentException('An authenticated user must carry a scalar identifier.');
        }

        return (string) $id;
    }

    private function write(string $brandId, string $userId, ConnectionStatus $status): void
    {
        $this->connection()->setex(
            self::connectionKey($brandId, $userId),
            self::TTL_SECONDS,
            $status->value.':'.CarbonImmutable::now()->getTimestamp(),
        );
    }

    /**
     * A missing key is Offline; an explicit `away` stays Away; an `online`
     * older than the heartbeat window decays to Away rather than lying.
     */
    private function decode(?string $value): ConnectionStatus
    {
        if ($value === null || $value === '') {
            return ConnectionStatus::Offline;
        }

        [$status, $seenAt] = array_pad(explode(':', $value, 2), 2, '');

        $declared = ConnectionStatus::tryFrom($status) ?? ConnectionStatus::Offline;

        if ($declared !== ConnectionStatus::Online) {
            return $declared;
        }

        return (int) $seenAt >= CarbonImmutable::now()->getTimestamp() - self::ONLINE_SECONDS
            ? ConnectionStatus::Online
            : ConnectionStatus::Away;
    }

    /**
     * The default connection, not a dedicated database: this is a handful
     * of short strings that expire on their own, not a rush queue whose
     * keyspace has to survive somebody else's `FLUSHDB`
     * (`RedisWaitingRoomStore`, which does earn its own).
     */
    private function connection(): Connection
    {
        return Redis::connection();
    }
}
