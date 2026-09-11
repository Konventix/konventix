<?php

declare(strict_types=1);

namespace Konventix\Registration\Console;

use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;
use Konventix\Registration\Events\RegistrationOwnershipAnnounced;
use Konventix\Registration\Models\Registration;

/**
 * Re-emits `RegistrationOwnershipAnnounced` for every registration, so
 * Admission can backfill `admissions.buyer_user_id` on rows that predate
 * that column (design doc §13, 2026-09-11 amendment "a registration-derived
 * admission is anonymous today"). Same shape as `event:replay-details` and
 * `identity:replay-profiles`.
 *
 * Deliberately its own event rather than a replay of `RegistrationConfirmed`:
 * that event also drives the confirmation mail
 * (`MailOnRegistrationConfirmed`), and re-emitting it here would re-send
 * every confirmation mail ever sent. `RegistrationOwnershipAnnounced`
 * carries only the three fields a projection needs — **only a projection
 * may ever listen to it**, and this command must never grow into a general
 * "replay everything about a registration" tool.
 *
 * Idempotent on the consuming side by construction:
 * `FillBuyerOnRegistrationOwnershipAnnounced` only ever fills a null
 * `buyer_user_id`, so running this command any number of times, against any
 * subset of registrations, changes nothing once every admission has one.
 */
final class AnnounceRegistrationOwnershipCommand extends Command
{
    protected $signature = 'registration:announce-ownership {--chunk=500 : Registrations per database round trip}';

    protected $description = 'Re-emit RegistrationOwnershipAnnounced for every registration so Admission can backfill admissions.buyer_user_id';

    public function handle(): int
    {
        $chunk = max(1, (int) $this->option('chunk'));
        $announced = 0;

        Registration::withoutBrandScope()
            ->orderBy('id')
            ->chunkById($chunk, function (Collection $registrations) use (&$announced): void {
                /** @var Registration $registration */
                foreach ($registrations as $registration) {
                    event(new RegistrationOwnershipAnnounced(
                        registrationId: $registration->id,
                        brandId: $registration->brand_id,
                        registrantUserId: $registration->registrant_user_id,
                    ));

                    $announced++;
                }
            });

        $this->components->info("Announced ownership for {$announced} registration(s).");

        return self::SUCCESS;
    }
}
