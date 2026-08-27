<?php

declare(strict_types=1);

namespace Konventix\Commerce\WaitingRoom;

use Carbon\CarbonImmutable;
use Illuminate\Redis\Connections\Connection;
use Illuminate\Support\Facades\Redis;

/**
 * `WaitingRoomStore` on Redis (ADR-0047 point 5) — the door a real rush
 * stands behind. One sorted set per brand for the queue (score =
 * `microtime(true)`, so `ZPOPMIN` always pops arrival order); one hash per
 * ticket holding what a holding page (Task 13) needs to show; one string
 * per signed-in user so a second visit finds the same ticket; one counter
 * per brand+IP-hash for the per-IP cap.
 *
 * `Redis::connection(config('commerce.waiting_room.connection'))` — its own
 * connection (`config/database.php`'s `waiting-room` block, a separate
 * Redis DB from cache and sessions), so a `FLUSHDB` against one never
 * drops the other, and queue traffic never contends with session/cache
 * traffic on the same keyspace.
 */
final class RedisWaitingRoomStore implements WaitingRoomStore
{
    /**
     * A ticket's hash outlives its queue membership — a holding page still
     * needs `intendedUrl()`/`admittedUntil()` well after `admitNext()` pops
     * it — but must not linger forever if it is never admitted at all.
     */
    private const TICKET_TTL_SECONDS = 7200;

    private const USER_TTL_SECONDS = 7200;

    private const IP_TTL_SECONDS = 3600;

    public function enqueue(string $brandId, string $ticketId, string $intendedUrl, ?string $userId): int
    {
        $redis = $this->connection();
        $ticketKey = $this->ticketKey($brandId, $ticketId);

        $redis->zadd($this->queueKey($brandId), microtime(true), $ticketId);
        $redis->hmset($ticketKey, [
            'url' => $intendedUrl,
            'user' => $userId ?? '',
            'admitted_at' => '',
            'expires_at' => '',
        ]);
        $redis->expire($ticketKey, self::TICKET_TTL_SECONDS);

        if ($userId !== null) {
            $redis->setex($this->userKey($brandId, $userId), self::USER_TTL_SECONDS, $ticketId);
        }

        return $this->positionOf($brandId, $ticketId) ?? 1;
    }

    public function positionOf(string $brandId, string $ticketId): ?int
    {
        $rank = $this->connection()->zrank($this->queueKey($brandId), $ticketId);

        return is_int($rank) ? $rank + 1 : null;
    }

    /**
     * The `[member => score, ...]` shape this reads relies on phpredis'
     * `zPopMin` specifically (`REDIS_CLIENT=phpredis`, pinned in
     * `.env.example`) — Predis' return shape for the same call differs,
     * and a silent switch between the two would reorder or drop arrivals
     * without a single line here changing. `tests/RedisWaitingRoomStoreTest.php`
     * runs this against a real Redis connection and is the guard: it fails
     * loudly if a client swap or a phpredis upgrade ever changes the shape.
     */
    public function admitNext(string $brandId, int $count): array
    {
        if ($count <= 0) {
            return [];
        }

        $popped = $this->connection()->zpopmin($this->queueKey($brandId), $count);

        if (! is_array($popped)) {
            return [];
        }

        // phpredis' zPopMin returns [member => score, ...] with the lowest
        // scores (earliest arrivals) first — exactly arrival order.
        return array_map(strval(...), array_keys($popped));
    }

    public function markAdmitted(string $brandId, string $ticketId, CarbonImmutable $admittedAt, CarbonImmutable $expiresAt): void
    {
        $redis = $this->connection();
        $ticketKey = $this->ticketKey($brandId, $ticketId);

        $redis->hmset($ticketKey, [
            'admitted_at' => $admittedAt->toIso8601String(),
            'expires_at' => $expiresAt->toIso8601String(),
        ]);
        $redis->expire($ticketKey, self::TICKET_TTL_SECONDS);
    }

    public function isAdmitted(string $brandId, string $ticketId): bool
    {
        $expiresAt = $this->admittedUntil($brandId, $ticketId);

        return $expiresAt !== null && $expiresAt->isFuture();
    }

    public function admittedUntil(string $brandId, string $ticketId): ?CarbonImmutable
    {
        $value = $this->stringField($brandId, $ticketId, 'expires_at');

        return $value === null ? null : CarbonImmutable::parse($value);
    }

    public function depth(string $brandId): int
    {
        return (int) $this->connection()->zcard($this->queueKey($brandId));
    }

    public function intendedUrl(string $brandId, string $ticketId): ?string
    {
        return $this->stringField($brandId, $ticketId, 'url');
    }

    public function ticketForUser(string $brandId, string $userId): ?string
    {
        $value = $this->connection()->get($this->userKey($brandId, $userId));

        return is_string($value) ? $value : null;
    }

    public function forget(string $brandId, string $ticketId): void
    {
        $redis = $this->connection();

        $redis->del($this->ticketKey($brandId, $ticketId));
        $redis->zrem($this->queueKey($brandId), $ticketId);
    }

    public function incrementIpAttempts(string $brandId, string $ipHash): int
    {
        $redis = $this->connection();
        $key = $this->ipKey($brandId, $ipHash);
        $count = (int) $redis->incr($key);

        if ($count === 1) {
            $redis->expire($key, self::IP_TTL_SECONDS);
        }

        return $count;
    }

    public function recordAdmissionRun(string $brandId): void
    {
        $this->connection()->set($this->lastAdmissionKey($brandId), CarbonImmutable::now()->toIso8601String());
    }

    public function lastAdmissionAt(string $brandId): ?CarbonImmutable
    {
        $value = $this->connection()->get($this->lastAdmissionKey($brandId));

        return is_string($value) && $value !== '' ? CarbonImmutable::parse($value) : null;
    }

    private function stringField(string $brandId, string $ticketId, string $field): ?string
    {
        $value = $this->connection()->hget($this->ticketKey($brandId, $ticketId), $field);

        return is_string($value) && $value !== '' ? $value : null;
    }

    private function connection(): Connection
    {
        return Redis::connection(config('commerce.waiting_room.connection'));
    }

    private function queueKey(string $brandId): string
    {
        return "waiting-room:{$brandId}:queue";
    }

    private function ticketKey(string $brandId, string $ticketId): string
    {
        return "waiting-room:{$brandId}:ticket:{$ticketId}";
    }

    private function userKey(string $brandId, string $userId): string
    {
        return "waiting-room:{$brandId}:user:{$userId}";
    }

    private function ipKey(string $brandId, string $ipHash): string
    {
        return "waiting-room:{$brandId}:ip:{$ipHash}";
    }

    private function lastAdmissionKey(string $brandId): string
    {
        return "waiting-room:{$brandId}:last_admission";
    }
}
