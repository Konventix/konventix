<?php

declare(strict_types=1);

namespace Konventix\Platform\Blocks\Types;

use Konventix\Platform\Blocks\BlockType;
use Konventix\Platform\Blocks\ResolvesBlockProps;
use Konventix\Platform\Data\Blocks\HeroBlockData;
use Konventix\Platform\Data\Blocks\HeroResolvedData;
use Konventix\Platform\Media\MediaLibrary;

/**
 * Platform's opening/landing-hero block: heading, optional subheading, and
 * an optional call-to-action link and image.
 *
 * The image needs server-side resolution because the payload stores a media
 * ULID, not a URL (ADR-0043): only `MediaLibrary` may turn a ULID into
 * something renderable (ADR-0035), and the URL it produces depends on which
 * disk the media lives on — which is deployment state, not page content.
 */
class HeroBlock implements BlockType, ResolvesBlockProps
{
    public function key(): string
    {
        return 'hero';
    }

    public function module(): string
    {
        return 'platform';
    }

    public function component(): string
    {
        return 'platform/blocks/HeroBlock';
    }

    public function dataClass(): string
    {
        return HeroBlockData::class;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function resolve(array $data): array
    {
        $payload = HeroBlockData::from($data);

        // Resolved per render, not injected: block types are registered at
        // boot into a registry a long-lived Octane worker shares, and
        // MediaLibrary reaches request-scoped disk resolution (ADR-0043
        // point 6). A boot-time instance would pin one request's resolver
        // into every later request that worker serves.
        $image = $payload->imageMediaId === null
            ? null
            : app(MediaLibrary::class)->referenceFor($payload->imageMediaId);

        return (new HeroResolvedData(
            heading: $payload->heading,
            subheading: $payload->subheading,
            ctaLabel: $payload->ctaLabel,
            ctaUrl: $payload->ctaUrl,
            imageUrl: $image['url'] ?? null,
            imageAlt: $image['altText'] ?? null,
        ))->toArray();
    }
}
