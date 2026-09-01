<?php

declare(strict_types=1);

namespace Konventix\Identity\Actions;

use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;
use Konventix\Identity\Events\BrandMembershipProfileUpdated;
use Konventix\Identity\Models\BrandMembership;
use Konventix\Identity\Models\User;
use Konventix\Platform\Actions\StoreMediaUpload;
use Konventix\Platform\Brands\BrandContext;
use Konventix\Platform\Media\MediaLibrary;

/**
 * The single source for setting, pointing at, and removing a membership's
 * avatar or banner (ADR-0051 §1) — `$kind` is `'avatar'|'banner'`. Task 5's
 * Steam avatar sync reuses `pointAt()` rather than duplicating the usage
 * bookkeeping.
 */
final class UpdateMembershipImage
{
    public function __construct(
        private readonly MediaLibrary $media,
        private readonly StoreMediaUpload $uploads,
    ) {}

    public function set(User $user, string $kind, UploadedFile $file): void
    {
        // BrandContext is a scoped binding — resolved here, at call time,
        // never captured on this action (it is not per-request itself).
        $brandId = app(BrandContext::class)->currentOrFail()->getKey();

        $item = $this->uploads->handle(
            brandId: $brandId,
            file: $file,
            altText: null,
            uploadedByUserId: $user->getKey(),
        );

        $this->pointAt($user, $kind, $item->getKey());
    }

    public function pointAt(User $user, string $kind, string $mediaId): void
    {
        $membership = $this->membershipFor($user);
        $column = $kind.'_media_id';
        $previous = $membership->{$column};

        if (! $this->media->belongsToBrand($mediaId, $membership->brand_id)) {
            throw ValidationException::withMessages(['file' => __('Choose an image from this brand.')]);
        }

        $this->media->recordUsage($mediaId, 'identity', 'membership', $membership->getKey());
        $membership->forceFill([$column => $mediaId])->save();

        if ($previous !== null && $previous !== $mediaId) {
            $this->media->releaseUsage($previous, 'identity', 'membership', $membership->getKey());
        }

        $this->announce($membership);
    }

    public function remove(User $user, string $kind): void
    {
        $membership = $this->membershipFor($user);
        $column = $kind.'_media_id';

        if ($membership->{$column} !== null) {
            $this->media->releaseUsage($membership->{$column}, 'identity', 'membership', $membership->getKey());
            $membership->forceFill([$column => null])->save();
        }

        $this->announce($membership);
    }

    private function membershipFor(User $user): BrandMembership
    {
        $membership = BrandMembership::query()->where('user_id', $user->getKey())->first();

        if ($membership === null) {
            // A face is shown to a brand's community; without a membership
            // there is no community to show it to.
            throw ValidationException::withMessages(['file' => __('You are not a member of this brand yet.')]);
        }

        return $membership;
    }

    private function announce(BrandMembership $membership): void
    {
        event(new BrandMembershipProfileUpdated(
            userId: $membership->user_id,
            brandId: $membership->brand_id,
            avatarUrl: $this->urlOrNull($membership->avatar_media_id),
            bannerUrl: $this->urlOrNull($membership->banner_media_id),
            firstSeenAt: $membership->first_seen_at->toIso8601String(),
            updatedAt: now()->toIso8601String(),
        ));
    }

    private function urlOrNull(?string $mediaId): ?string
    {
        return $mediaId === null ? null : ($this->media->urlFor($mediaId, 'thumb') ?? $this->media->urlFor($mediaId));
    }
}
