<?php

declare(strict_types=1);

namespace Konventix\Event\Data;

use Spatie\LaravelData\Attributes\DataCollectionOf;
use Spatie\LaravelData\Data;

/**
 * What the "Live hub" admin page submits (design §6, ADR-0009): the whole
 * layout, every time. `cards` must be *present* rather than required so an
 * operator can hide everything — an empty list is a legal layout, a missing
 * one is a broken form. The key pattern is `<module>.<name>`, the shape
 * every `HubCard`/`HubLink` key has; anything else could not name a
 * registry entry and is refused here rather than stored and dropped later.
 */
final class UpdateHubLayoutData extends Data
{
    /**
     * @param  array<int, HubLayoutEntryData>  $cards
     */
    public function __construct(
        #[DataCollectionOf(HubLayoutEntryData::class)]
        public readonly array $cards,
        public readonly bool $hubReplacesHome,
    ) {}

    /**
     * @return array<string, array<int, mixed>>
     */
    public static function rules(): array
    {
        return [
            'cards' => ['present', 'array', 'max:50'],
            'cards.*.key' => ['required', 'string', 'max:80', 'regex:/^[a-z0-9-]+\.[a-z0-9-]+$/'],
            'cards.*.visible' => ['required', 'boolean'],
            'hubReplacesHome' => ['required', 'boolean'],
        ];
    }
}
