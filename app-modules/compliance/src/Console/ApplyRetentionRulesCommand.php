<?php

declare(strict_types=1);

namespace Konventix\Compliance\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Konventix\Compliance\Enums\RetentionClockStart;
use Konventix\Compliance\Events\RetentionPeriodElapsed;
use Konventix\Compliance\Models\DataSubjectRequest;
use Konventix\Compliance\Models\RetentionClock;
use Konventix\Compliance\Models\RetentionRule;

/**
 * The daily retention sweep (SPEC "Owns" — retention rules and the data
 * lifecycle).
 *
 * It deletes almost nothing itself. For every active rule it works out the
 * cutoff and emits `RetentionPeriodElapsed`; the module that owns the data
 * acts on its own categories. Compliance publishing a rule and another module
 * discharging it is the whole point of the design — a sweep that reached into
 * foreign tables would be the same violation the fan-out was built to avoid
 * (ADR-0004).
 *
 * The one thing it does delete is Compliance's own leftovers: expired subject
 * export archives, which are files rather than rows and whose expiry is
 * already recorded on the request.
 *
 * Deliberately re-emits every day while a rule stays elapsed — see
 * `RetentionPeriodElapsed` for why that is a feature and not a missing flag.
 */
final class ApplyRetentionRulesCommand extends Command
{
    protected $signature = 'compliance:apply-retention {--dry-run : List what would be emitted without emitting it}';

    protected $description = 'Emit RetentionPeriodElapsed for every retention rule whose period has run out.';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $emitted = 0;

        /** @var RetentionRule $rule */
        foreach (RetentionRule::query()->where('active', true)->get() as $rule) {
            $emitted += match ($rule->clock_start) {
                RetentionClockStart::RecordAge => $this->emitForRecordAge($rule, $dryRun),
                RetentionClockStart::EventConcluded => $this->emitForConcludedEvents($rule, $dryRun),
            };
        }

        $archives = $this->purgeExpiredArchives($dryRun);

        $this->info(sprintf(
            '%s %d retention signal(s); %s %d expired export archive(s).',
            $dryRun ? 'Would emit' : 'Emitted',
            $emitted,
            $dryRun ? 'would delete' : 'deleted',
            $archives,
        ));

        return self::SUCCESS;
    }

    private function emitForRecordAge(RetentionRule $rule, bool $dryRun): int
    {
        $cutoff = now()->subMonths($rule->retention_months);

        $this->line("  {$rule->data_category}: everything before {$cutoff->toDateString()}");

        if (! $dryRun) {
            event(new RetentionPeriodElapsed(
                ruleId: $rule->getKey(),
                brandId: $rule->brand_id,
                dataCategory: $rule->data_category,
                cutoffAt: $cutoff->toIso8601String(),
            ));
        }

        return 1;
    }

    /**
     * One signal per concluded event whose period has run out, carrying the
     * event id — a consumer of an event-scoped category needs to know *which*
     * event's data is out of retention, not merely that some date passed.
     */
    private function emitForConcludedEvents(RetentionRule $rule, bool $dryRun): int
    {
        $clocks = RetentionClock::query()
            ->when($rule->brand_id !== null, fn ($query) => $query->where('brand_id', $rule->brand_id))
            ->where('concluded_at', '<=', now()->subMonths($rule->retention_months))
            ->get();

        foreach ($clocks as $clock) {
            $this->line("  {$rule->data_category}: event {$clock->event_id}");

            if (! $dryRun) {
                event(new RetentionPeriodElapsed(
                    ruleId: $rule->getKey(),
                    brandId: $clock->brand_id,
                    dataCategory: $rule->data_category,
                    cutoffAt: $clock->concluded_at->copy()->addMonths($rule->retention_months)->toIso8601String(),
                    eventId: $clock->event_id,
                ));
            }
        }

        return $clocks->count();
    }

    /**
     * Compliance's own leftovers. An export archive stops being downloadable
     * at `archive_expires_at`, but the file stayed on the disk — a second copy
     * of a person's whole record, kept past the moment it had a reason to
     * exist. The row survives; only the file and its pointer go.
     */
    private function purgeExpiredArchives(bool $dryRun): int
    {
        $expired = DataSubjectRequest::withoutBrandScope()
            ->whereNotNull('archive_path')
            ->where('archive_expires_at', '<=', now())
            ->get();

        foreach ($expired as $request) {
            if ($dryRun) {
                continue;
            }

            Storage::disk((string) $request->archive_disk)->delete((string) $request->archive_path);

            $request->forceFill(['archive_path' => null, 'archive_disk' => null])->save();
        }

        return $expired->count();
    }
}
