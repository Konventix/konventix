<?php

declare(strict_types=1);

namespace Konventix\Admission\Console;

use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;
use Konventix\Admission\Actions\ProjectEventPresence;
use Konventix\Admission\Enums\CheckInResult;
use Konventix\Admission\Events\ParticipantAdmitted;
use Konventix\Admission\Events\ParticipantPresenceChanged;
use Konventix\Admission\Models\Admission;
use Konventix\Admission\Models\CheckIn;
use Konventix\Admission\Models\EventPresence;
use Konventix\Admission\Models\EventSummary;

/**
 * Re-emits `ParticipantAdmitted` and `ParticipantPresenceChanged` for every
 * presence row that still has hub access, carrying the row's current
 * values — the same shape as `event:replay-details` and
 * `identity:replay-profiles`, and what both SPECs mean when they call
 * Platform's `platform_hub_access` "rebuildable by replay" (ADR-0004).
 * Idempotent: every consumer upserts by `(event_id, user_id)`.
 *
 * `--seed` is the other half, and the one the rollout needs. Presence only
 * exists from the moment this code shipped, so deploying 0.9.0 in the
 * middle of a running event would leave everyone already inside the hall
 * without a hub until they happen to scan again — which on an
 * `unlimited`-re-entry event may be never. Seeding walks that event's
 * accepted check-ins oldest-first through `ProjectEventPresence::checkIn()`,
 * so the rows, the day windows and the events come out of the same code
 * path a live scan uses, then closes the windows that have since passed
 * (the sweep the scheduler runs, run once here so a seeded row from an
 * earlier day is not left claiming access it lost).
 *
 * Scans are replayed for the *named* event only: `--seed` without
 * `--event` is refused rather than quietly walking every check-in a brand
 * ever took.
 */
final class ReplayPresenceCommand extends Command
{
    protected $signature = 'admission:replay-presence
        {--event= : Limit to one event by ULID instead of every presence row}
        {--seed : Rebuild the event\'s presence rows from its accepted check-ins first (requires --event)}
        {--chunk=200 : Rows per database round trip}';

    protected $description = 'Re-emit the participant events for admitted presence rows, and optionally seed presence for one event from its accepted check-ins';

    public function handle(ProjectEventPresence $presence): int
    {
        $only = $this->option('event');
        $only = is_string($only) && $only !== '' ? $only : null;
        $chunk = max(1, (int) $this->option('chunk'));

        if ($this->option('seed')) {
            if ($only === null) {
                $this->components->error('--seed needs --event: seeding walks one event\'s check-ins, never every brand\'s.');

                return self::FAILURE;
            }

            $this->components->info("Seeded {$this->seed($presence, $only, $chunk)} check-in(s) into presence.");
        }

        $this->components->info("Replayed {$this->replay($only, $chunk)} presence row(s).");

        return self::SUCCESS;
    }

    /**
     * Every accepted scan of the event, oldest first, back through the
     * projection. Order is what makes this right: the same holder's `in`
     * then `out` has to land in that order, or the row ends up saying they
     * are on site when they left. Rejected scans and scans that never
     * resolved to an admission are not presence and are skipped, as they
     * were live.
     */
    private function seed(ProjectEventPresence $presence, string $eventId, int $chunk): int
    {
        $seeded = 0;

        CheckIn::withoutBrandScope()
            ->where('result', CheckInResult::Accepted)
            ->whereIn('admission_id', Admission::withoutBrandScope()->where('event_id', $eventId)->select('id'))
            ->orderBy('scanned_at')
            ->orderBy('id')
            ->chunk($chunk, function (Collection $scans) use ($presence, $eventId, &$seeded): void {
                /** @var CheckIn $scan */
                foreach ($scans as $scan) {
                    if ($scan->admission_id === null) {
                        continue;
                    }

                    $presence->checkIn(
                        $scan->brand_id,
                        $eventId,
                        $scan->admission_id,
                        $scan->direction,
                        CarbonImmutable::parse($scan->scanned_at),
                    );

                    $seeded++;
                }
            });

        // A scan from an earlier day rebuilt a row with a window that has
        // already passed; the sweep is what turns that back into "no
        // access", and it is idempotent, so running it here costs nothing.
        $presence->closeExpiredWindows(CarbonImmutable::now());

        return $seeded;
    }

    /**
     * The rows that still have access, announced again. The display fields
     * travel on `ParticipantAdmitted` itself (design §5.1), so the event
     * carries the row's own values plus the event's name and slug from
     * Admission's summary projection — looked up once per event, not once
     * per row.
     *
     * `ParticipantPresenceChanged` follows with the row's last direction,
     * because `handleAdmitted()` writes `on_site: true` by definition: a
     * replay without it would put everyone who had gone home back on site.
     *
     * Both markers are moved, so a replay also settles a row whose original
     * announcement was interrupted.
     */
    private function replay(?string $eventId, int $chunk): int
    {
        $replayed = 0;
        /** @var array<string, EventSummary|null> $summaries */
        $summaries = [];

        $query = EventPresence::withoutBrandScope()->where('hub_access', true);

        if ($eventId !== null) {
            $query->where('event_id', $eventId);
        }

        $query->orderBy('id')->chunkById($chunk, function (Collection $rows) use (&$replayed, &$summaries): void {
            /** @var EventPresence $row */
            foreach ($rows as $row) {
                $summary = $summaries[$row->event_id] ??= EventSummary::withoutBrandScope()->where('event_id', $row->event_id)->first();

                event(new ParticipantAdmitted(
                    userId: $row->user_id,
                    eventId: $row->event_id,
                    brandId: $row->brand_id,
                    admissionId: $row->admission_id,
                    admittedAt: $row->first_admitted_at->toIso8601String(),
                    windowEndsAt: $row->window_ends_at?->toIso8601String(),
                    dayLabel: $row->day_label,
                    eventName: $summary?->name,
                    eventSlug: $summary?->slug,
                ));

                event(new ParticipantPresenceChanged(
                    $row->user_id,
                    $row->event_id,
                    $row->brand_id,
                    $row->last_direction,
                    $row->last_check_in_at->toIso8601String(),
                ));

                $row->forceFill([
                    'announced_hub_access' => true,
                    'announced_direction' => $row->last_direction,
                ])->save();

                $replayed++;
            }
        });

        return $replayed;
    }
}
