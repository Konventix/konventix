<?php

declare(strict_types=1);

namespace Konventix\Identity\Sessions;

use Illuminate\Contracts\Cache\Repository as CacheContract;
use Illuminate\Session\CacheBasedSessionHandler;

/**
 * The redis session handler the Security page's session list is read from
 * (D-IDP-17).
 *
 * Laravel 13 has no `RedisSessionHandler`: `SessionManager::createRedisDriver()`
 * builds a plain `CacheBasedSessionHandler` over the redis cache store,
 * which writes an opaque blob per session id and records nothing about who
 * owns it — so `SecurityController` could only ever offer the list on the
 * database driver. This subclass adds the index
 * `Illuminate\Session\DatabaseSessionHandler` gets for free from its table:
 * on every write of a signed-in session it stores the same four fields that
 * handler puts in the `sessions` row and adds the id to a per-user set.
 *
 * The handler is the only hook that sees every moment: activity on every
 * write, id rotation at login (`Store::migrate()` destroys the old id
 * first), every login path alike, and logout — where Fortify's
 * `AuthenticatedSessionController::destroy()` empties the guard *before*
 * `$request->session()->invalidate()`, so `destroy()` cannot ask `auth()`
 * who the owner was and reads it out of the stored hash instead.
 *
 * Nothing request-shaped is captured in the constructor. Under Octane the
 * handler lives for the worker's whole lifetime and receives no new
 * container: `GiveNewApplicationInstanceToSessionManager` is an empty
 * method, and `GiveNewApplicationInstanceToDatabaseSessionHandler` re-
 * containers a `DatabaseSessionHandler` only. Request, user and IP are
 * therefore resolved per call through the helpers, which read the current
 * sandbox container (`.ai/rules/providers.md`).
 */
final class IndexedRedisSessionHandler extends CacheBasedSessionHandler
{
    public function __construct(CacheContract $cache, int $minutes, private readonly SessionIndex $index)
    {
        parent::__construct($cache, $minutes);
    }

    /**
     * {@inheritdoc}
     */
    public function write($sessionId, $data): bool
    {
        $written = parent::write($sessionId, $data);

        $userId = $this->currentUserId();

        // A guest session is written like any other but stays out of the
        // index: there is no user whose list it could appear on, and an
        // ownerless hash would only be a key nothing ever reads.
        if ($userId === null) {
            return $written;
        }

        $this->index->record($sessionId, $userId, [
            'user_id' => $userId,
            'ip_address' => $this->ipAddress(),
            'user_agent' => $this->userAgent(),
            'last_activity' => now()->getTimestamp(),
        ], $this->minutes * 60);

        return $written;
    }

    /**
     * {@inheritdoc}
     */
    public function destroy($sessionId): bool
    {
        // Before the payload: `forget()` reads the owner out of the meta
        // hash, and the hash is the only place that fact survives a logout.
        $this->index->forget($sessionId);

        return parent::destroy($sessionId);
    }

    /**
     * The signed-in user's key, or null for a guest. Read per call, never
     * stored — see the class docblock.
     */
    private function currentUserId(): ?string
    {
        if (! app()->bound('auth')) {
            return null;
        }

        $id = auth()->id();

        return $id === null ? null : (string) $id;
    }

    private function ipAddress(): ?string
    {
        return app()->bound('request') ? request()->ip() : null;
    }

    /**
     * Truncated the way `Illuminate\Session\DatabaseSessionHandler::userAgent()`
     * truncates it, so a row written by either handler carries the same value.
     */
    private function userAgent(): ?string
    {
        if (! app()->bound('request')) {
            return null;
        }

        $agent = request()->header('User-Agent');

        return $agent === null
            ? null
            : mb_substr(mb_convert_encoding((string) $agent, 'UTF-8'), 0, 500);
    }
}
