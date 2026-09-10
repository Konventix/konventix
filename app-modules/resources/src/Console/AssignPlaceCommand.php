<?php

declare(strict_types=1);

namespace Konventix\Resources\Console;

use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Collection as SupportCollection;
use Illuminate\Validation\ValidationException;
use Konventix\Resources\Actions\AssignPlacesToRegistration;
use Konventix\Resources\Enums\ClaimState;
use Konventix\Resources\Models\Resource;
// Aliased: an unqualified "Resource" in a docblock generic collides with
// PHP's `resource` pseudo-type and pint's docblock fixer silently lowercases
// it (the same trap fixed in tests/Helpers.php).
use Konventix\Resources\Models\Resource as BookableResource;
use Konventix\Resources\Models\ResourceClaim;

/**
 * The operator's headless pick (seating design §7.3, Q6): places a
 * registration's live seat claim on places named by ref, through the same
 * `AssignPlacesToRegistration` the picker uses. Nothing here is a second
 * booking path.
 *
 * Runs as the `operator` actor with no user id: there is no signed-in user
 * behind an artisan call, and being able to run artisan at all is the
 * trust boundary — the `BookOnBehalf` check applies to the console UI, not
 * here (recorded in the design's §13). No brand in context, so every query
 * is `withoutBrandScope()`; `--brand` narrows when the same registration id
 * should ever appear under two brands (it cannot, ULIDs are unique, but the
 * option makes the intent explicit on a shared dev database).
 *
 * A ref is unique per plan, not per event: when the event has two published
 * plans with the same ref the command refuses rather than guessing.
 */
final class AssignPlaceCommand extends Command
{
    protected $signature = 'resources:assign-place
        {registration : Registration ULID whose seat claim is placed}
        {refs* : Place refs from the plan, one per place (e.g. A-01 A-02)}
        {--brand= : Brand ULID, to narrow the claim lookup}';

    protected $description = 'Place a registration\'s seat claim on places named by ref, as the operator (Resources).';

    public function handle(AssignPlacesToRegistration $assignPlaces): int
    {
        $registrationId = (string) $this->argument('registration');
        /** @var list<string> $refs */
        $refs = array_values(array_unique(array_map('strval', (array) $this->argument('refs'))));
        $brandId = $this->option('brand');

        $claim = ResourceClaim::withoutBrandScope()
            ->where('registration_id', $registrationId)
            ->when(is_string($brandId) && $brandId !== '', fn ($query) => $query->where('brand_id', $brandId))
            ->whereIn('state', [ClaimState::Unplaced->value, ClaimState::Placed->value])
            ->orderBy('created_at')
            ->first();

        if ($claim === null) {
            $this->components->error(sprintf('Registration [%s] has no live seat claim.', $registrationId));

            return self::FAILURE;
        }

        $places = $this->placesByRef($claim, $refs);

        $missing = array_values(array_diff($refs, $places->keys()->all()));

        if ($missing !== []) {
            $this->components->error(sprintf('No place with ref %s on a published plan of event [%s].', implode(', ', $missing), $claim->event_id));

            return self::FAILURE;
        }

        $ambiguous = $places->filter(fn (Collection $rows): bool => $rows->count() > 1)->keys()->all();

        if ($ambiguous !== []) {
            $this->components->error(sprintf('Ref %s is ambiguous: it exists on more than one published plan of the event. Use the plan editor for this one.', implode(', ', $ambiguous)));

            return self::FAILURE;
        }

        /** @var list<string> $placeIds */
        $placeIds = $places->map(fn (Collection $rows): string => (string) $rows->first()->id)->values()->all();

        try {
            $bookings = $assignPlaces->handle($claim, $placeIds, AssignPlacesToRegistration::ACTOR_OPERATOR, null);
        } catch (ValidationException $exception) {
            foreach ($exception->errors() as $messages) {
                foreach ($messages as $message) {
                    $this->components->error($message);
                }
            }

            return self::FAILURE;
        }

        $this->components->info(sprintf(
            'Placed registration %s on %s (claim %s).',
            $registrationId,
            implode(', ', array_map(fn ($booking): string => (string) ($booking->resource()->withoutGlobalScopes()->value('ref') ?? $booking->resource_id), $bookings)),
            $claim->id,
        ));

        return self::SUCCESS;
    }

    /**
     * The event's published places carrying one of the refs, grouped by
     * ref so ambiguity is visible.
     *
     * groupBy()'s outer container is a plain Support\Collection, not an
     * Eloquent\Collection: Eloquent\Collection's TModel generic bound
     * requires a Model, which a nested Collection of places is not (the
     * same shape `PlanConflicts::detect()` pins).
     *
     * @param  list<string>  $refs
     * @return SupportCollection<string, Collection<int, BookableResource>>
     */
    private function placesByRef(ResourceClaim $claim, array $refs): SupportCollection
    {
        /** @var SupportCollection<string, Collection<int, BookableResource>> $grouped */
        $grouped = Resource::withoutBrandScope()
            ->select('resources.*')
            ->join('resource_plans', 'resource_plans.id', '=', 'resources.plan_id')
            ->where('resources.brand_id', $claim->brand_id)
            ->whereNull('resources.deleted_at')
            ->whereIn('resources.ref', $refs)
            ->where('resource_plans.event_id', $claim->event_id)
            ->whereNotNull('resource_plans.published_at')
            ->get()
            ->groupBy('ref');

        return $grouped;
    }
}
