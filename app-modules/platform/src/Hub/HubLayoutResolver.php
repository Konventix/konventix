<?php

declare(strict_types=1);

namespace Konventix\Platform\Hub;

use Illuminate\Support\Facades\Cache;
use Konventix\Platform\Models\HubLayout;

/**
 * The operator's hub layout for one event, as Platform projected it
 * (design §6) — one cache read per render, none when nobody composed one
 * because the empty layout is cached too. `ProjectHubLayout` forgets
 * `hub-layout:{event}` on every change, so a stale answer lives until the
 * next save and never longer than `TTL_SECONDS` if a forget is missed.
 *
 * Hands back the layout as stored. Merging it with what the registry holds
 * today — layout order first, unknown keys dropped, unlisted registrations
 * appended visible — is the hub page's job, because it needs the viewer's
 * gated registry, not this table.
 *
 * Reads by `event_id` alone, escaping the brand scope (ADR-0010): an event
 * belongs to exactly one brand, and the answer must not depend on whether a
 * brand is resolved on the request that asks (the admin host has none,
 * ADR-0029). Stateless, so a singleton is safe (`.ai/rules/providers.md`).
 */
final class HubLayoutResolver
{
    public const TTL_SECONDS = 600;

    /**
     * @return array{cards: list<array{key: string, visible: bool}>, hubReplacesHome: bool}
     */
    public function for(string $eventId): array
    {
        /** @var array{cards: list<array{key: string, visible: bool}>, hubReplacesHome: bool} */
        return Cache::remember(self::key($eventId), self::TTL_SECONDS, function () use ($eventId): array {
            $layout = HubLayout::withoutBrandScope()->where('event_id', $eventId)->first();

            if ($layout === null) {
                return ['cards' => [], 'hubReplacesHome' => false];
            }

            return ['cards' => $layout->cards, 'hubReplacesHome' => $layout->hub_replaces_home];
        });
    }

    public static function key(string $eventId): string
    {
        return "hub-layout:{$eventId}";
    }

    public static function forget(string $eventId): void
    {
        Cache::forget(self::key($eventId));
    }
}
