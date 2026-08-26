<?php

declare(strict_types=1);

namespace Database\Seeders\Demo;

use Konventix\Event\Models\Event;
use Konventix\Platform\Media\MediaLibrary;
use Konventix\Platform\Models\Brand;
use Konventix\Platform\Models\MediaItem;
use Konventix\Platform\Models\StaticPage;

/**
 * The brand's public pages, built from the block types modules register with
 * Platform's CMS: a hero, a countdown to the next event, a card row, the
 * latest-news feed Content contributes, and prose.
 *
 * Blocks reference their media by ULID and never by URL (ADR-0043) — the
 * page resolves them through the library at render time, which is also what
 * makes the console refuse to delete a file a page is using.
 *
 * Idempotent per slug.
 */
final class DemoStaticPageSeeder
{
    public function __construct(private readonly MediaLibrary $library) {}

    /**
     * @param  array<string, MediaItem>  $media
     */
    public function handle(Brand $brand, ?Event $firstEvent, array $media): void
    {
        foreach (['imprint' => 'Imprint', 'about' => 'About', 'privacy' => 'Privacy'] as $slug => $title) {
            // Scoped to the brand in context; idempotent on re-runs.
            StaticPage::query()->firstOrCreate(
                ['slug' => $slug],
                [
                    'title' => $title,
                    'published_at' => now(),
                    'blocks' => [
                        ['type' => 'text', 'data' => ['heading' => $title, 'body' => fake()->paragraphs(2, true)]],
                    ],
                ],
            );
        }

        // The '/' route (routes/web.php) renders this page through the block
        // pipeline when it exists; brands without one fall back to Welcome.
        $home = StaticPage::query()->firstOrCreate(
            ['slug' => 'home'],
            [
                'title' => $brand->name,
                'published_at' => now(),
                'blocks' => $this->homeBlocks($brand, $firstEvent, $media),
            ],
        );

        $hero = $media['hero'] ?? null;

        if ($hero !== null) {
            // The page references the file by ULID (ADR-0043) — recording the
            // usage is what stops the console deleting it out from under the
            // page. Idempotent, so a re-run does not stack references.
            $this->library->recordUsage($hero->getKey(), 'platform', 'static_page', $home->getKey());
        }
    }

    /**
     * @param  array<string, MediaItem>  $media
     * @return array<int, array{type: string, data: array<string, mixed>}>
     */
    private function homeBlocks(Brand $brand, ?Event $firstEvent, array $media): array
    {
        $hero = $media['hero'] ?? null;

        $blocks = [
            [
                'type' => 'hero',
                'data' => [
                    'heading' => $brand->name,
                    'subheading' => 'Event-agnostic event management, done right.',
                    'ctaLabel' => $firstEvent !== null ? 'View the event' : null,
                    'ctaUrl' => $firstEvent !== null ? '/events/'.$firstEvent->slug : null,
                    // A media ULID, never a URL: the block resolves it
                    // through MediaLibrary at render time (ADR-0043).
                    'imageMediaId' => $hero?->getKey(),
                ],
            ],
        ];

        if ($firstEvent !== null) {
            $blocks[] = [
                'type' => 'countdown',
                'data' => ['eventId' => $firstEvent->getKey(), 'label' => 'Doors open in'],
            ];
        }

        $blocks[] = [
            'type' => 'cards',
            'data' => [
                'cards' => [
                    ['title' => 'Registration', 'body' => 'Sign up in minutes with flexible registration types.', 'icon' => 'ticket'],
                    ['title' => 'Venue', 'body' => 'Everything you need to know before you arrive.', 'icon' => 'map-pin'],
                    ['title' => 'Community', 'body' => 'Join a growing community across every event.', 'icon' => 'users'],
                ],
            ],
        ];

        // Content's own block type, which is the point: modules register
        // blocks with Platform's CMS rather than Platform knowing about them.
        $blocks[] = [
            'type' => 'latest-news',
            'data' => ['heading' => 'Latest news', 'limit' => 3],
        ];

        $blocks[] = [
            'type' => 'text',
            'data' => ['heading' => 'About '.$brand->name, 'body' => fake()->paragraphs(2, true)],
        ];

        return $blocks;
    }
}
