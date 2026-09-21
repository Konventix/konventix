<?php

declare(strict_types=1);

namespace Konventix\Registration\Data;

use Konventix\Registration\Models\EventSummary;
use Spatie\LaravelData\Attributes\DataCollectionOf;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * Server-resolved props for the `tickets-for-event` block's Vue component:
 * the same `PublicOfferData` list the event landing page's `event.landing`
 * slot renders, under a heading the editor chose or, failing that, the
 * event's name.
 *
 * `found: false` is the graceful shape for an event the block may not
 * show: a ULID that no longer resolves, another brand's event (the
 * `BelongsToBrand` scope on the lookup hides it), or one that is still a
 * draft, unlisted or archived — a static page is addressed by no event, so
 * it owes `EventSummary::scopeListedPublicly()` the way `/shop` does, and
 * naming a draft convention on the home page is exactly the leak #240
 * closed. The component renders nothing rather than failing the page.
 */
#[TypeScript]
final class TicketsForEventResolvedData extends Data
{
    /**
     * @param  list<PublicOfferData>  $offers
     */
    public function __construct(
        public readonly bool $found,
        public readonly ?string $heading,
        public readonly ?string $eventName,

        /** @var list<PublicOfferData> */
        #[DataCollectionOf(PublicOfferData::class)]
        public readonly array $offers = [],
    ) {}

    /**
     * Named `of*` rather than `from*` on purpose: laravel-data routes every
     * public static `from*` method through its magic-creation resolver
     * (.ai/rules/data.md).
     *
     * @param  list<PublicOfferData>  $offers
     */
    public static function ofSummary(EventSummary $summary, ?string $heading, array $offers): self
    {
        return new self(
            found: true,
            // A cleared editor input arrives as '' — that is "no heading",
            // and the event's name is the sensible default.
            heading: filled($heading) ? $heading : $summary->name,
            eventName: $summary->name,
            offers: $offers,
        );
    }

    public static function notFound(): self
    {
        return new self(
            found: false,
            heading: null,
            eventName: null,
            offers: [],
        );
    }
}
