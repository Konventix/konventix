<?php

declare(strict_types=1);

namespace Konventix\Support\Enums;

/**
 * Who reads a message in the thread (design §4.3). Two values and no third:
 * `external` is what the reporter sees through "My support requests" or
 * their signed link, `internal` is a staff note that never leaves the
 * console.
 *
 * The distinction is load-bearing for privacy, not a display preference —
 * every reporter-facing query filters on it, so a new value would have to
 * be added to each of them deliberately.
 */
enum MessageVisibility: string
{
    case External = 'external';
    case Internal = 'internal';

    public function label(): string
    {
        return match ($this) {
            self::External => __('Reply to the reporter'),
            self::Internal => __('Internal note'),
        };
    }

    public function isVisibleToReporter(): bool
    {
        return $this === self::External;
    }
}
