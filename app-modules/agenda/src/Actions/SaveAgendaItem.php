<?php

declare(strict_types=1);

namespace Konventix\Agenda\Actions;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Konventix\Agenda\Data\AgendaItemData;
use Konventix\Agenda\Enums\ItemState;
use Konventix\Agenda\Events\AgendaItemRescheduled;
use Konventix\Agenda\Models\AgendaItem;

/**
 * Writes one programme entry, new or existing (design §4.2).
 *
 * Three things it is careful about:
 *
 * 1. The brand comes from the caller, not from context. The editor runs on
 *    the operator admin host, which resolves no brand (ADR-0029), so
 *    `brand_id` is force-filled before the first save and
 *    `BelongsToBrand`'s creating hook finds it already set.
 * 2. Both instants are anchored with `CarbonImmutable::parse()->utc()`
 *    before they touch the model. Eloquent's datetime cast writes the
 *    wall-clock digits it is handed without converting the zone, so
 *    "15:00+01:00" would otherwise land in the column as 15:00 UTC — an
 *    hour late, silently. Sub-project A paid for this lesson.
 * 3. `AgendaItemRescheduled` is emitted only when an *already published*
 *    item's times actually changed. Editing a draft moves nothing anybody
 *    has seen, and re-saving a published item with the same times is not
 *    news (design §7).
 *
 * `state` is not written here on purpose — see `AgendaItemData`.
 */
final class SaveAgendaItem
{
    public function handle(string $eventId, string $brandId, AgendaItemData $data, ?AgendaItem $item = null): AgendaItem
    {
        $startsAt = self::instant($data->startsAt);
        $endsAt = self::instant($data->endsAt);

        $rescheduled = $item !== null
            && $item->state === ItemState::Published
            && (! self::sameInstant($item->starts_at, $startsAt) || ! self::sameInstant($item->ends_at, $endsAt));

        $saved = DB::transaction(function () use ($eventId, $brandId, $data, $item, $startsAt, $endsAt): AgendaItem {
            $saved = $item ?? new AgendaItem;

            if (! $saved->exists) {
                $saved->forceFill([
                    'brand_id' => $brandId,
                    'state' => ItemState::Draft,
                ]);
            }

            $saved->fill([
                'event_id' => $eventId,
                'track_id' => $data->trackId,
                'location_id' => $data->locationId,
                'title' => $data->title,
                'description' => $data->description,
                'starts_at' => $startsAt,
                'ends_at' => $endsAt,
                'location_note' => $data->locationNote,
                'speaker' => $data->speaker,
            ])->save();

            return $saved;
        });

        if ($rescheduled) {
            event(new AgendaItemRescheduled(
                agendaItemId: $saved->id,
                eventId: $saved->event_id,
                brandId: $saved->brand_id,
                startsAt: $saved->starts_at?->toIso8601String(),
                endsAt: $saved->ends_at?->toIso8601String(),
            ));
        }

        return $saved;
    }

    /**
     * The posted string as an instant, anchored to UTC. See the class
     * docblock for why the `->utc()` is not decoration.
     */
    private static function instant(?string $value): ?CarbonImmutable
    {
        return $value === null ? null : CarbonImmutable::parse($value)->utc();
    }

    /**
     * Two instants are the same moment, or both absent. `equalTo()` rather
     * than `==` so that an item read back from Postgres compares against a
     * freshly parsed string by the moment they name, not by their objects.
     */
    private static function sameInstant(?CarbonImmutable $stored, ?CarbonImmutable $posted): bool
    {
        if ($stored === null || $posted === null) {
            return $stored === null && $posted === null;
        }

        return $stored->equalTo($posted);
    }
}
