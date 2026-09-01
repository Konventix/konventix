<?php

declare(strict_types=1);

namespace Konventix\Identity\Enums;

/**
 * Who may see an account's public page (ADR-0052). `internal` is the
 * default and matches ADR-0051 §7's original members-only rule unchanged;
 * `public` is the owner's explicit opt-in to open the page to anyone,
 * including signed-out guests; `event` narrows it to viewers who share at
 * least one CONFIRMED event registration with the owner on the brand being
 * viewed, resolved from Community's own `community_event_participations`
 * read model (Community may not read Registration's tables, ADR-0004).
 *
 * Comments stay internal-scope under every value (ADR-0052 §2) — this enum
 * only ever governs the profile fields themselves.
 */
enum ProfileVisibility: string
{
    case Event = 'event';
    case Internal = 'internal';
    case Public = 'public';
}
