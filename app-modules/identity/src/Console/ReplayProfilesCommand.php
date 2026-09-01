<?php

declare(strict_types=1);

namespace Konventix\Identity\Console;

use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;
use Konventix\Identity\Events\UserProfileUpdated;
use Konventix\Identity\Models\User;

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

    public function handle(): int
    {
        $chunk = max(1, (int) $this->option('chunk'));
        $replayed = 0;

        User::query()
            ->orderBy('id')
            ->chunkById($chunk, function (Collection $users) use (&$replayed): void {
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
                        locale: $user->locale,
                        updatedAt: ($user->updated_at ?? now())->toIso8601String(),
                    ));

                    $replayed++;
                }
            });

        $this->info("Replayed {$replayed} account profile(s).");

        return self::SUCCESS;
    }
}
