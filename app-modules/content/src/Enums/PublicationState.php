<?php

declare(strict_types=1);

namespace Konventix\Content\Enums;

/**
 * Where an entry is in its publish lifecycle.
 *
 * `Scheduled` is distinct from `Published` with a future date, because the
 * question "is this live right now" should be answerable without arithmetic,
 * and because a scheduled entry that is later unpublished must not silently
 * go live when its date arrives.
 */
enum PublicationState: string
{
    case Draft = 'draft';
    case Scheduled = 'scheduled';
    case Published = 'published';
    case Unpublished = 'unpublished';
    case Archived = 'archived';
}
