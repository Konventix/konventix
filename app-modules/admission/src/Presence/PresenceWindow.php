<?php

declare(strict_types=1);

namespace Konventix\Admission\Presence;

use Carbon\CarbonImmutable;
use Konventix\Admission\Enums\ReentryPolicy;
use Konventix\Admission\Models\EntranceEventProfile;

/**
 * When a participant's hub access closes (D-HUB-01). Once-per-day events
 * close at the scanned day's `day_ends_at` — before noon meaning the night
 * after that day, from noon on meaning the same day — with 00:00 of the
 * following day as the default. Every other policy stays open until the
 * event concludes, which is a `null` end here and `EventConcluded`'s job.
 *
 * Dates are resolved in the event's own zone (ADR-0027) and the result is
 * handed back as a UTC instant so the sweep compares plain timestamps.
 */
final class PresenceWindow
{
    /**
     * @return array{ends_at: CarbonImmutable|null, day_label: string|null}
     */
    public function for(EntranceEventProfile $profile, ?string $timezone, CarbonImmutable $scannedAt): array
    {
        $zone = $timezone ?? 'UTC';
        $local = $scannedAt->setTimezone($zone);
        $day = $profile->dayCovering($local);

        if ($profile->reentry_policy !== ReentryPolicy::OncePerDay) {
            return ['ends_at' => null, 'day_label' => $day['label'] ?? null];
        }

        $date = CarbonImmutable::parse($day['date'] ?? $local->toDateString(), $zone);
        [$hour, $minute] = array_map(intval(...), explode(':', $day['day_ends_at'] ?? '00:00'));

        $end = $date->setTime($hour, $minute);

        if ($hour < 12) {
            $end = $end->addDay();
        }

        return ['ends_at' => $end->utc(), 'day_label' => $day['label'] ?? null];
    }
}
