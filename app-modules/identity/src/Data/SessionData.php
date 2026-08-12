<?php

declare(strict_types=1);

namespace Konventix\Identity\Data;

use Illuminate\Support\Carbon;
use Konventix\Identity\Models\Session;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * The Inertia props shape and generated TypeScript type for one row of the
 * session list on the Security settings page (ADR-0009, SPEC.md "Owns" —
 * "Sessions: the list of a user's active sessions and their revocation").
 */
#[TypeScript]
final class SessionData extends Data
{
    public function __construct(
        public readonly string $id,
        public readonly ?string $ipAddress,
        public readonly ?string $userAgent,
        public readonly string $lastActiveAt,
        public readonly bool $isCurrentDevice,
    ) {}

    public static function fromModel(Session $session, string $currentSessionId): self
    {
        return new self(
            id: $session->id,
            ipAddress: $session->ip_address,
            userAgent: $session->user_agent,
            lastActiveAt: Carbon::createFromTimestamp($session->last_activity)->toIso8601String(),
            isCurrentDevice: $session->id === $currentSessionId,
        );
    }
}
