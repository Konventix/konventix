<?php

declare(strict_types=1);

namespace Konventix\Registration\Blocks;

use Konventix\Platform\Blocks\BlockType;
use Konventix\Platform\Blocks\HasBlockEditor;
use Konventix\Platform\Blocks\ResolvesBlockProps;
use Konventix\Registration\Data\TicketsForEventBlockData;
use Konventix\Registration\Data\TicketsForEventResolvedData;
use Konventix\Registration\Models\EventSummary;
use Konventix\Registration\Offers\PublicOffers;

/**
 * Registration's static-page block (bugfix round 2026-09-21, Task 7): one
 * event's public offers on any page the editor composes — the home page's
 * "get your ticket" section, without sending the visitor through the event
 * list first.
 *
 * Registration owns it because Registration owns the offers: the same
 * `PublicOffers` query the `event.landing` slot renders, through the same
 * `PublicOfferData`. Platform sees only a `BlockType` (ADR-0003), and the
 * stored payload is the event's ULID plus a heading — the event itself is
 * read from this module's own `EventSummary` projection, never from Event's
 * tables (ADR-0004).
 *
 * Unlike the landing slot, this block has to gate the *event* as well: a
 * static page is addressed by no event, so nothing upstream has decided
 * whether it may be shown. It owes the listing rule `/shop` owes
 * (`EventSummary::scopeListedPublicly()` — announced or published, public
 * rather than unlisted, not archived), and answers `found: false` otherwise.
 */
final class TicketsForEventBlock implements BlockType, HasBlockEditor, ResolvesBlockProps
{
    public function key(): string
    {
        return 'tickets-for-event';
    }

    public function module(): string
    {
        return 'registration';
    }

    public function component(): string
    {
        return 'registration/blocks/TicketsForEventBlock';
    }

    public function dataClass(): string
    {
        return TicketsForEventBlockData::class;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function resolve(array $data): array
    {
        $payload = TicketsForEventBlockData::from($data);

        // `BelongsToBrand`'s global scope already restricts the projection
        // to the current brand, so a stale or cross-brand ULID resolves to
        // null rather than to another brand's event.
        $summary = EventSummary::query()
            ->listedPublicly()
            ->where('event_id', $payload->eventId)
            ->first();

        if ($summary === null) {
            return TicketsForEventResolvedData::notFound()->toArray();
        }

        return TicketsForEventResolvedData::ofSummary(
            $summary,
            $payload->heading,
            PublicOffers::forEvent($summary->event_id),
        )->toArray();
    }

    public function label(): string
    {
        return __('Tickets for event');
    }

    public function editorComponent(): string
    {
        return 'registration/blocks/editors/TicketsForEventEditor';
    }

    /**
     * @return array<string, mixed>
     */
    public function defaultData(): array
    {
        return ['eventId' => '', 'heading' => null];
    }
}
