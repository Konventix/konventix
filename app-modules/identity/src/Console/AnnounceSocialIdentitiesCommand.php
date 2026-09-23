<?php

declare(strict_types=1);

namespace Konventix\Identity\Console;

use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;
use Konventix\Identity\Events\SocialIdentityAnnounced;
use Konventix\Identity\Models\SocialIdentity;

/**
 * Re-emits `SocialIdentityAnnounced` for every linked provider identity, so a
 * read model created after people linked their accounts can be backfilled.
 * Competition's linked-account projection for the Steam signup rule is the
 * first such read model (competition design §5.6).
 *
 * It uses the same pattern as `registration:announce-ownership` and
 * `admission:announce-ownership`: only a projection listens, so the command
 * can run any number of times, and once every row has been projected a run
 * changes nothing. It never re-emits `SocialIdentityLinked`, because that
 * event writes an audit entry (see the event's docblock).
 */
final class AnnounceSocialIdentitiesCommand extends Command
{
    protected $signature = 'identity:announce-social-identities {--chunk=500 : Identities per database round trip}';

    protected $description = 'Re-emit SocialIdentityAnnounced for every linked provider identity so downstream read models can be backfilled';

    public function handle(): int
    {
        $chunk = max(1, (int) $this->option('chunk'));
        $announced = 0;

        SocialIdentity::query()
            ->orderBy('id')
            ->chunkById($chunk, function (Collection $identities) use (&$announced): void {
                /** @var SocialIdentity $identity */
                foreach ($identities as $identity) {
                    event(new SocialIdentityAnnounced(
                        userId: $identity->user_id,
                        provider: $identity->provider,
                        linkedAt: $identity->linked_at->toIso8601String(),
                    ));
                    $announced++;
                }
            });

        $this->components->info("Announced {$announced} linked identity(ies).");

        return self::SUCCESS;
    }
}
