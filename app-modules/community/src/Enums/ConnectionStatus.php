<?php

declare(strict_types=1);

namespace Konventix\Community\Enums;

/**
 * The connection axis of design §5 — Online · Away · Offline — and nothing
 * else. It is **not** an attendance record and must never be read as one:
 * the durable half of presence is `community_event_presence`, an admission
 * badge projected from Admission (ADR-0067 decision 2), and the two axes
 * compose rather than substitute. "Checked-In + Offline" is a real state:
 * at the event, phone in a pocket.
 *
 * These three values live only in Redis with a TTL and are never persisted
 * (ADR-0067 decision 3), so this enum deliberately has no cast on any
 * model: there is no column for it to cast.
 *
 * `Offline` is what `ConnectionState` answers for a key that has expired or
 * was never written. It is a derived absence, never something written down.
 */
enum ConnectionStatus: string
{
    case Online = 'online';
    case Away = 'away';
    case Offline = 'offline';
}
