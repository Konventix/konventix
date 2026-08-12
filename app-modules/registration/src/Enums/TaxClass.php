<?php

declare(strict_types=1);

namespace Konventix\Registration\Enums;

/**
 * The tax class a `RegistrationType`'s price declaration names (ADR-0017,
 * ADR-0030). Deliberately a *class*, not a hand-typed percentage: Commerce
 * resolves the actual rate at settlement time and may legitimately override
 * the treatment on evidence Registration never sees (reverse charge, the
 * operator's §19 UStG election). "19%" on a type screen is an intention,
 * not a guarantee.
 */
enum TaxClass: string
{
    case Standard = 'standard';
    case Reduced = 'reduced';
}
