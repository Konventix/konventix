<?php

declare(strict_types=1);

namespace Konventix\Identity\Console;

use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;
use Konventix\Identity\Events\BrandMembershipProfileUpdated;
use Konventix\Identity\Events\UserProfileUpdated;
use Konventix\Identity\Models\BrandMembership;
use Konventix\Identity\Models\User;
use Konventix\Platform\Media\MediaLibrary;

/**
 * Re-emits `UserProfileUpdated` for every account, carrying the row's current
 * values. Read models in other modules (Content authors, Messaging
 * recipients, Registration registrants) project from this event and
 * therefore only know accounts created after their projection existed;
 * a replay rebuilds them from the contract, which is what ADR-0004
 * promises (SPEC.md "Public contract").
 *
 * Idempotent by construction: every consumer upserts, so a second replay
 * converges on the same rows. Anonymized accounts are replayed too — their
 * scrubbed name is the current truth, and a read model still showing the
 * old one would be the bug.
 */
class ReplayProfilesCommand extends Command
{
    protected $signature = 'identity:replay-profiles {--chunk=500 : Accounts per database round trip}';

    protected $description = 'Re-emit UserProfileUpdated for every account so downstream read models can be rebuilt';

    public function handle(MediaLibrary $media): int
    {
        $chunk = max(1, (int) $this->option('chunk'));
        $replayed = 0;

        User::query()
            ->orderBy('id')
            ->chunkById($chunk, function (Collection $users) use (&$replayed, $media): void {
                /** @var User $user */
                foreach ($users as $user) {
                    event(new UserProfileUpdated(
                        userId: $user->getKey(),
                        email: $user->email,
                        displayName: $user->name,
                        username: $user->username,
                        tagline: $user->tagline,
                        statusEmoji: $user->status_emoji,
                        socialLinks: $user->social_links ?? [],
                        visibility: $user->profile_visibility->value,
                        listedInAttendees: $user->listed_in_attendees,
                        locale: $user->locale ?? (string) config('app.locale'),
                        updatedAt: ($user->updated_at ?? now())->toIso8601String(),
                    ));

                    // Every brand membership too — its avatar/banner and
                    // "member since" (ruling 2) are their own contract, one
                    // event per membership rather than per account.
                    foreach (BrandMembership::withoutBrandScope()->where('user_id', $user->getKey())->get() as $membership) {
                        event(new BrandMembershipProfileUpdated(
                            userId: $membership->user_id,
                            brandId: $membership->brand_id,
                            avatarUrl: $this->urlOrNull($media, $membership->avatar_media_id),
                            bannerUrl: $this->urlOrNull($media, $membership->banner_media_id),
                            firstSeenAt: $membership->first_seen_at->toIso8601String(),
                            updatedAt: ($membership->updated_at ?? now())->toIso8601String(),
                        ));
                    }

                    $replayed++;
                }
            });

        $this->info("Replayed {$replayed} account profile(s).");

        return self::SUCCESS;
    }

    private function urlOrNull(MediaLibrary $media, ?string $mediaId): ?string
    {
        return $mediaId === null ? null : ($media->urlFor($mediaId, 'thumb') ?? $media->urlFor($mediaId));
    }
}
