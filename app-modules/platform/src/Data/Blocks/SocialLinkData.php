<?php

declare(strict_types=1);

namespace Konventix\Platform\Data\Blocks;

use Spatie\LaravelData\Attributes\Validation\In;
use Spatie\LaravelData\Attributes\Validation\Max;
use Spatie\LaravelData\Attributes\Validation\Nullable;
use Spatie\LaravelData\Attributes\Validation\Regex;
use Spatie\LaravelData\Attributes\Validation\Required;
use Spatie\LaravelData\Attributes\Validation\StringType;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * One entry in a `social-links` block: a network, its destination, and an
 * optional display label.
 */
#[TypeScript]
final class SocialLinkData extends Data
{
    public const array NETWORKS = ['github', 'x', 'facebook', 'instagram', 'youtube', 'twitch', 'discord', 'mastodon', 'linkedin', 'website'];

    public function __construct(
        #[Required, StringType, In(['github', 'x', 'facebook', 'instagram', 'youtube', 'twitch', 'discord', 'mastodon', 'linkedin', 'website'])]
        public readonly string $network,
        // Same pattern as FooterLinkData's `url` — a root-relative path, an
        // absolute http(s) URL, or a mailto address, and nothing else, which
        // keeps a `javascript:` destination out of a link every visitor
        // clicks.
        #[Required, StringType, Max(2048), Regex('#^(/|https?://|mailto:)#')]
        public readonly string $url,
        #[Nullable, StringType, Max(40)]
        public readonly ?string $label = null,
    ) {}
}
