<?php

declare(strict_types=1);

namespace Konventix\Community\Presence;

use Carbon\CarbonImmutable;
use Illuminate\Redis\Connections\Connection;
use Illuminate\Support\Facades\Redis;
use Konventix\Community\Enums\ConnectionStatus;

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
    public function online(string $userId): void
    {
        $this->write($userId, ConnectionStatus::Online);
    }

    /**
     * The client saying its tab is hidden, rather than the read deriving it
     * from a stale heartbeat. Same key, same TTL — only the status differs.
     */
    public function away(string $userId): void
    {
        $this->write($userId, ConnectionStatus::Away);
    }

    public function statusFor(string $userId): ConnectionStatus
    {
        $value = $this->connection()->get(self::connectionKey($userId));

        return $this->decode(is_string($value) ? $value : null);
    }

    /**
     * A heartbeat on the room axis: this account is looking at this room.
     * A sorted set scored by the instant, so a stale member is pruned by
     * score on the next read and the key itself expires when a room goes
     * quiet.
     */
    public function enterRoom(string $roomId, string $userId): void
    {
        $redis = $this->connection();
        $key = self::roomKey($roomId);

        $redis->zadd($key, CarbonImmutable::now()->getTimestamp(), $userId);
        $redis->expire($key, self::TTL_SECONDS);
    }

    public function leaveRoom(string $roomId, string $userId): void
    {
        $this->connection()->zrem(self::roomKey($roomId), $userId);
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
    public function forRoom(string $roomId): array
    {
        $redis = $this->connection();
        $key = self::roomKey($roomId);

        $redis->zremrangebyscore($key, '-inf', (string) (CarbonImmutable::now()->getTimestamp() - self::TTL_SECONDS));

        $members = $redis->zrange($key, 0, -1);

        if (! is_array($members)) {
            return [];
        }

        $state = [];

        foreach ($members as $member) {
            $userId = (string) $member;
            $status = $this->statusFor($userId);

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
    public static function connectionKey(string $userId): string
    {
        return "community:connection:{$userId}";
    }

    public static function roomKey(string $roomId): string
    {
        return "community:room:{$roomId}:active";
    }

    private function write(string $userId, ConnectionStatus $status): void
    {
        $this->connection()->setex(
            self::connectionKey($userId),
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
