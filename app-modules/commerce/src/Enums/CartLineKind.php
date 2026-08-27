<?php

declare(strict_types=1);

namespace Konventix\Commerce\Enums;

/**
 * What a cart line reserves (ADR-0047 point 3). A `Product` line locks
 * `ProductVariant` stock and is keyed by the variant's own id; a
 * `Registration` line holds a `Registration` row's spot instead — Task 10
 * creates those, keyed by the settlement request's `request_key` rather
 * than `ref_id` alone, since a person may hold more than one pending
 * registration at once.
 */
enum CartLineKind: string
{
    case Product = 'product';
    case Registration = 'registration';
}
