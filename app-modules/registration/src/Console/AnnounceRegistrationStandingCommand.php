<?php

declare(strict_types=1);

namespace Konventix\Registration\Console;

use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;
use Konventix\Registration\Enums\RegistrationStatus;
use Konventix\Registration\Events\RegistrationStandingAnnounced;
use Konventix\Registration\Models\Registration;

/**
 * Re-emits `RegistrationStandingAnnounced` for every registration, so a read
 * model created after the registrations were written can be backfilled.
 * Competition's entry-eligibility projection is the first such read model
 * (competition design §5.1): without this, a registration that never emitted
 * its events, such as one the Eventula migration wrote directly, never lets
 * its registrant enter a tournament.
 *
 * It uses the same pattern as `registration:announce-ownership` and
 * `identity:announce-social-identities`: only a projection listens, so the
 * command can run any number of times, and once every row has been projected
 * a run changes nothing. It never re-emits `RegistrationSubmitted`,
 * `RegistrationConfirmed` or `RegistrationRejected` (see the event's
 * docblock).
 */
final class AnnounceRegistrationStandingCommand extends Command
{
    protected $signature = 'registration:announce-standing {--chunk=500 : Registrations per database round trip}';

    protected $description = 'Re-emit RegistrationStandingAnnounced for every registration so downstream read models can be backfilled';

    public function handle(): int
    {
        $chunk = max(1, (int) $this->option('chunk'));
        $announced = 0;

        Registration::withoutBrandScope()
            ->orderBy('id')
            ->chunkById($chunk, function (Collection $registrations) use (&$announced): void {
                /** @var Registration $registration */
                foreach ($registrations as $registration) {
                    event(new RegistrationStandingAnnounced(
                        registrationId: $registration->id,
                        eventId: $registration->event_id,
                        brandId: $registration->brand_id,
                        registrantUserId: $registration->registrant_user_id,
                        confirmed: $registration->status === RegistrationStatus::Confirmed,
                    ));

                    $announced++;
                }
            });

        $this->components->info("Announced the standing of {$announced} registration(s).");

        return self::SUCCESS;
    }
}
