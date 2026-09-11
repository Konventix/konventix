<?php

declare(strict_types=1);

namespace Konventix\Resources\Console;

use Illuminate\Console\Command;
use Konventix\Resources\Actions\ExportPlan;
use Konventix\Resources\Models\Plan;

/**
 * Prints a plan's document in canonical JSON (design §6.4, §9.2) — pipe it
 * into a file to move a plan between environments or to refresh the
 * committed example. Runs without a brand, so the lookup escapes the scope.
 */
final class PlanExportCommand extends Command
{
    protected $signature = 'resources:plan-export {plan : Plan ULID}';

    protected $description = 'Print a plan document as canonical JSON';

    public function handle(ExportPlan $export): int
    {
        $plan = Plan::withoutBrandScope()->whereKey((string) $this->argument('plan'))->first();

        if ($plan === null) {
            $this->error('No plan with that id.');

            return self::FAILURE;
        }

        $this->output->write($export->handle($plan));

        return self::SUCCESS;
    }
}
