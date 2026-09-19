<?php

declare(strict_types=1);

namespace Konventix\Admission\Console;

use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;
use Konventix\Admission\Events\AdmissionOwnershipAnnounced;
use Konventix\Admission\Models\Admission;

/**
 * Re-emits `AdmissionOwnershipAnnounced` for every admission, so Resources
 * can fill `resource_admissions` for rows that predate that read model
 * (#292). Same shape as `registration:announce-ownership`, and the same
 * rule: only a projection listens, so running it any number of times
 * changes nothing once every row has been projected.
 */
final class AnnounceAdmissionOwnershipCommand extends Command
{
    protected $signature = 'admission:announce-ownership {--chunk=500 : Admissions per database round trip}';

    protected $description = 'Re-emit AdmissionOwnershipAnnounced for every admission so Resources can backfill its admission read model';

    public function handle(): int
    {
        $chunk = max(1, (int) $this->option('chunk'));
        $announced = 0;

        Admission::withoutBrandScope()
            ->orderBy('id')
            ->chunkById($chunk, function (Collection $admissions) use (&$announced): void {
                /** @var Admission $admission */
                foreach ($admissions as $admission) {
                    event(new AdmissionOwnershipAnnounced(
                        admissionId: $admission->id,
                        registrationId: $admission->registration_id,
                        eventId: $admission->event_id,
                        brandId: $admission->brand_id,
                        holderUserId: $admission->holder_user_id,
                        buyerUserId: $admission->buyer_user_id,
                        state: $admission->state->value,
                    ));
                    $announced++;
                }
            });

        $this->components->info("Announced ownership for {$announced} admission(s).");

        return self::SUCCESS;
    }
}
