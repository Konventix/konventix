<?php

declare(strict_types=1);

namespace Konventix\Identity\Data;

use Illuminate\Support\Carbon;
use Konventix\Identity\Sessions\SessionSummary;
use Konventix\Identity\Support\UserAgent;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * The Inertia props shape and generated TypeScript type for one row of the
 * session list on the Security settings page (ADR-0009, SPEC.md "Owns" —
 * "Sessions: the list of a user's active sessions and their revocation").
 *
 * `device` is the readable line ("Chrome on Windows", see `Support\UserAgent`)
 * and `userAgent` the raw header it was read from, kept for the tooltip. The
 * IP address is the only locality there is: nothing here resolves it to a
 * place, so the UI labels it as an address and promises no "location".
 */
#[TypeScript]
final class SessionData extends Data
{
    public function __construct(
        public readonly string $id,
        public readonly ?string $ipAddress,
        public readonly ?string $userAgent,
        public readonly string $device,
        public readonly string $lastActiveAt,
        public readonly bool $isCurrentDevice,
    ) {}

    /**
     * Built from a `Sessions\SessionSummary` rather than the `sessions` row
     * it used to read (D-IDP-17): on the redis driver there is no row, only
     * the meta hash `Sessions\IndexedRedisSessionHandler` writes, and both
     * storages hand the page the identical four facts.
     */
    public static function fromSummary(SessionSummary $session, string $currentSessionId): self
    {
        return new self(
            id: $session->id,
            ipAddress: $session->ipAddress,
            userAgent: $session->userAgent,
            device: UserAgent::describe($session->userAgent),
            lastActiveAt: Carbon::createFromTimestamp($session->lastActivity)->toIso8601String(),
            isCurrentDevice: $session->id === $currentSessionId,
        );
    }
}
