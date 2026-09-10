<?php

declare(strict_types=1);

namespace Konventix\Resources\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Konventix\Platform\Environment\DeploymentEnvironment;
use Konventix\Resources\Actions\AssignPlacesToRegistration;
use Konventix\Resources\Actions\ReleaseResourceBooking;
use Konventix\Resources\Enums\BookingState;
use Konventix\Resources\Enums\ClaimState;
use Konventix\Resources\Enums\SeatingMode;
use Konventix\Resources\Models\Resource;
use Konventix\Resources\Models\ResourceBooking;
use Konventix\Resources\Models\ResourceClaim;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

/**
 * Proves, by actually racing processes, that N parallel picks of one place
 * end with exactly one confirmed booking — the property the seat picker
 * rests on (ADR-0020's exclusion constraint, seating design §7.3).
 *
 * Why a command and not a Pest test alone: Pest runs single-threaded inside
 * one transaction and can never have two picks contend; real contention
 * needs real processes on their own connections. It is also the shape an
 * operator can point at the dev cluster by hand. The pattern is
 * `commerce:probe-invoice-numbering`, and `SeatRaceProbeTest` runs it in CI.
 *
 * The probe writes real claim and booking rows for the place it is given —
 * that is the point — so it refuses production (ADR-0053) and, unless
 * `--keep`, releases the winner's booking and closes its claims afterwards.
 */
final class ProbeSeatRace extends Command
{
    private const float WORKER_TIMEOUT_SECONDS = 60.0;

    protected $signature = 'resources:probe-seat-race
        {place : ULID of a published place (resources row) the workers race for}
        {--workers=4 : Child processes picking in parallel}
        {--timeout= : Seconds a child may run before the probe gives up on it (default 60)}
        {--keep : Leave the winner\'s booking and the probe claims in place}
        {--worker : Internal — run as one child}
        {--claim= : Internal — the claim the child picks with}';

    protected $description = 'Prove that parallel picks of one place have exactly one winner — writes real rows, never run on production (Resources).';

    public function handle(DeploymentEnvironment $environment, AssignPlacesToRegistration $assignPlaces, ReleaseResourceBooking $release): int
    {
        if ($environment->isProduction()) {
            $this->components->error('Refusing to run on production: the probe books a real place (ADR-0053).');

            return self::FAILURE;
        }

        $placeId = (string) $this->argument('place');

        if ($this->option('worker')) {
            return $this->runAsWorker($assignPlaces, $placeId, (string) $this->option('claim'));
        }

        return $this->runAsParent($release, $placeId, max(1, (int) $this->option('workers')));
    }

    /**
     * One child: one pick, one word on stdout — `won` or `lost` — which the
     * parent parses. Nothing else is printed.
     */
    private function runAsWorker(AssignPlacesToRegistration $assignPlaces, string $placeId, string $claimId): int
    {
        $claim = ResourceClaim::withoutBrandScope()->whereKey($claimId)->firstOrFail();

        try {
            $assignPlaces->handle($claim, [$placeId], AssignPlacesToRegistration::ACTOR_OPERATOR, null);
            $this->output->writeln('won');
        } catch (ValidationException) {
            $this->output->writeln('lost');
        }

        return self::SUCCESS;
    }

    private function runAsParent(ReleaseResourceBooking $release, string $placeId, int $workers): int
    {
        $place = Resource::withoutBrandScope()
            ->select('resources.*', 'resource_plans.event_id as plan_event_id', 'resource_plans.published_at as plan_published_at')
            ->join('resource_plans', 'resource_plans.id', '=', 'resources.plan_id')
            ->whereKey($placeId)
            ->first();

        if ($place === null || $place->getAttribute('plan_published_at') === null) {
            $this->components->error(sprintf('No published place [%s]. Pass the ULID of a resources row on a published plan.', $placeId));

            return self::FAILURE;
        }

        $eventId = (string) $place->getAttribute('plan_event_id');
        $claimIds = $this->createClaims($place, $eventId, $workers);

        $this->components->info(sprintf('Racing %d worker(s) for place %s (%s) of event %s.', $workers, $place->ref ?? $place->label, $placeId, $eventId));

        $timeout = (float) ($this->option('timeout') ?: self::WORKER_TIMEOUT_SECONDS);
        [$results, $failures] = $this->runWorkers($placeId, $claimIds, $timeout);

        $won = count(array_filter($results, fn (string $result): bool => $result === 'won'));
        $lost = count(array_filter($results, fn (string $result): bool => $result === 'lost'));
        $confirmed = ResourceBooking::withoutBrandScope()
            ->where('resource_id', $placeId)
            ->whereIn('claim_id', $claimIds)
            ->where('state', BookingState::Confirmed->value)
            ->count();
        $placed = ResourceClaim::withoutBrandScope()->whereIn('id', $claimIds)->where('state', ClaimState::Placed->value)->count();

        foreach ([
            sprintf('%d worker(s) exited 0', $workers) => $failures === [],
            'exactly one winner' => $won === 1,
            sprintf('%d loser(s)', $workers - 1) => $lost === $workers - 1,
            'one confirmed booking on the place' => $confirmed === 1,
            'one claim placed' => $placed === 1,
        ] as $label => $held) {
            $this->components->twoColumnDetail($label, $held ? '<fg=green>ok</>' : '<fg=red>FAILED</>');
        }

        foreach ($failures as $failure) {
            $this->components->error($failure);
        }

        if (! $this->option('keep')) {
            $this->cleanUp($release, $placeId, $claimIds);
        }

        return ($failures === [] && $won === 1 && $lost === $workers - 1 && $confirmed === 1 && $placed === 1)
            ? self::SUCCESS
            : self::FAILURE;
    }

    /**
     * One `assign`-mode claim per worker on fictitious registrations, all
     * eligible for the place's category. Committed before the children
     * start: the parent runs outside any transaction.
     *
     * @return list<string>
     */
    private function createClaims(Resource $place, string $eventId, int $workers): array
    {
        $ids = [];

        for ($i = 0; $i < $workers; $i++) {
            $claim = new ResourceClaim([
                'event_id' => $eventId,
                'registration_id' => (string) Str::ulid(),
                'registrant_user_id' => null,
                'amendment_id' => null,
                'resource_type_ids' => [$place->resource_type_id],
                'seating_mode' => SeatingMode::Assign,
                'quantity' => 1,
                'state' => ClaimState::Unplaced,
            ]);
            $claim->forceFill(['brand_id' => $place->brand_id])->save();

            $ids[] = $claim->id;
        }

        return $ids;
    }

    /**
     * Starts every child at once — they have to overlap for the probe to
     * mean anything — with this process's own database configuration as
     * explicit environment (a child reading `.env` under the test runner
     * would probe the dev database and prove nothing). A child that hangs is
     * reported, not thrown: a lock nobody released is exactly what this
     * probe hunts.
     *
     * @param  list<string>  $claimIds
     * @return array{0: list<string>, 1: list<string>}
     */
    private function runWorkers(string $placeId, array $claimIds, float $timeout): array
    {
        $environment = $this->childEnvironment();

        /** @var list<Process> $processes */
        $processes = [];

        foreach ($claimIds as $claimId) {
            $process = new Process([
                PHP_BINARY,
                base_path('artisan'),
                'resources:probe-seat-race',
                $placeId,
                '--worker',
                '--claim='.$claimId,
            ], base_path(), $environment, null, $timeout);

            $process->start();

            $processes[] = $process;
        }

        $results = [];
        $failures = [];

        foreach ($processes as $worker => $process) {
            try {
                $process->wait();
            } catch (ProcessTimedOutException) {
                $failures[] = sprintf('Worker %d timed out after %ss', $worker, $timeout);

                continue;
            }

            if (! $process->isSuccessful()) {
                $failures[] = sprintf(
                    'Worker %d exited %s: %s',
                    $worker,
                    var_export($process->getExitCode(), true),
                    trim($process->getErrorOutput().' '.$process->getOutput()),
                );

                continue;
            }

            $results[] = trim($process->getOutput());
        }

        return [$results, $failures];
    }

    /**
     * @return array<string, string>
     */
    private function childEnvironment(): array
    {
        $connection = (string) config('database.default');

        /** @var array<string, mixed> $settings */
        $settings = (array) config('database.connections.'.$connection);

        return [
            'APP_ENV' => (string) config('app.env'),
            'DB_CONNECTION' => $connection,
            // Blank rather than absent: an inherited DB_URL would outrank
            // every setting below (ConfigurationUrlParser wins over the parts).
            'DB_URL' => '',
            'DB_HOST' => (string) ($settings['host'] ?? ''),
            'DB_PORT' => (string) ($settings['port'] ?? ''),
            'DB_DATABASE' => (string) ($settings['database'] ?? ''),
            'DB_USERNAME' => (string) ($settings['username'] ?? ''),
            'DB_PASSWORD' => (string) ($settings['password'] ?? ''),
        ];
    }

    /**
     * Gives the place back and closes the probe claims, so a dev plan is not
     * left with a phantom occupant. Through `ReleaseResourceBooking` like
     * every other release — never a direct write to `resource_bookings`.
     *
     * @param  list<string>  $claimIds
     */
    private function cleanUp(ReleaseResourceBooking $release, string $placeId, array $claimIds): void
    {
        $bookings = ResourceBooking::withoutBrandScope()
            ->where('resource_id', $placeId)
            ->whereIn('claim_id', $claimIds)
            ->whereIn('state', BookingState::occupyingValues())
            ->get();

        foreach ($bookings as $booking) {
            $release->handle($booking, BookingState::Released, 'operator_released');
        }

        ResourceClaim::withoutBrandScope()
            ->whereIn('id', $claimIds)
            ->update(['state' => ClaimState::Released->value]);

        $this->components->info('Cleaned up: winner booking released, probe claims closed (pass --keep to inspect them instead).');
    }
}
