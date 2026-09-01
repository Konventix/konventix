<?php

declare(strict_types=1);

namespace Konventix\Identity\Profile;

use Konventix\Identity\Models\BrandMembership;
use Konventix\Platform\Brands\BrandContext;
use Konventix\Platform\Media\MediaLibrary;

/**
 * The avatar URL behind the shared `auth.user` prop (ADR-0051 §5): the
 * current brand membership's `avatar_media_id`, resolved through Platform's
 * sanctioned media API — never a `MediaItem` read from outside Platform
 * (ADR-0035).
 *
 * `BrandContext` is resolved at call time rather than injected, because it
 * is a scoped binding and this resolver itself must not capture a per-request
 * value at construction (Octane, ADR-0029): on the admin console host, or
 * anywhere else no brand resolves, there is no brand-scoped membership to
 * read and the initials fallback renders instead.
 */
final class AvatarUrlResolver
{
    public function __construct(private readonly MediaLibrary $media) {}

    public function forUser(string $userId): ?string
    {
        if (app(BrandContext::class)->current() === null) {
            return null;
        }

        $mediaId = BrandMembership::query()->where('user_id', $userId)->value('avatar_media_id');

        if ($mediaId === null) {
            return null;
        }

        return $this->media->urlFor($mediaId, 'thumb') ?? $this->media->urlFor($mediaId);
    }
}
