<?php

declare(strict_types=1);

namespace Konventix\Platform\Operators;

/**
 * The operator's current pick of brand and event in the console shell,
 * held in the session on the admin host (ADR-0029 — the console has no
 * brand, so this is never a brand cookie). Lists that span brands or
 * events read it as the default of their own filter; it is a default the
 * user can clear per list, because "all brands" is a legitimate view of
 * an operator console.
 *
 * Holds no state of its own — every call reads the session — so a
 * long-lived Octane worker cannot leak one operator's pick into another
 * request.
 */
final class ConsoleSelection
{
    public const SESSION_BRAND = 'konventix.console.brand_id';

    public const SESSION_EVENT = 'konventix.console.event_id';

    public function brandId(): ?string
    {
        $value = session()->get(self::SESSION_BRAND);

        return is_string($value) && $value !== '' ? $value : null;
    }

    public function eventId(): ?string
    {
        $value = session()->get(self::SESSION_EVENT);

        return is_string($value) && $value !== '' ? $value : null;
    }

    public function select(?string $brandId, ?string $eventId): void
    {
        session()->put(self::SESSION_BRAND, $brandId);
        session()->put(self::SESSION_EVENT, $eventId);
    }
}
