<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Application;
use Illuminate\Console\Scheduling\ScheduleWorkCommand;
use Illuminate\Support\ProcessUtils;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * The framework's schedule:work, with one difference: the child
 * `schedule:run` is started with --quiet. The parent streams the child's
 * stdout and stderr through one writer, so the "Running [...]" task line,
 * written without a newline until the task ends, glues itself to whatever
 * JSON record a task's failure writes to stderr in the meantime, and the
 * record is no longer a record (ADR-0059). Structured schedule.task records
 * from ObservabilityServiceProvider replace the console lines.
 *
 * Registered under the same name; the application's command is added after
 * the framework's, and the later registration wins.
 */
final class ScheduleWorkQuietly extends ScheduleWorkCommand
{
    public function handle(): int
    {
        $this->components->info(
            'Running scheduled tasks quietly.',
            $this->getLaravel()->environment('local') ? OutputInterface::VERBOSITY_NORMAL : OutputInterface::VERBOSITY_VERBOSE,
        );

        $this->listenForSignals();

        return (int) $this->work(self::scheduleRunCommand(
            whisper: (bool) $this->option('whisper'),
            runOutputFile: $this->option('run-output-file'),
        ));
    }

    public static function scheduleRunCommand(bool $whisper, ?string $runOutputFile): string
    {
        $command = Application::formatCommandString('schedule:run').' --quiet';

        if ($whisper) {
            $command .= ' --whisper';
        }

        if ($runOutputFile !== null && $runOutputFile !== '') {
            $command .= ' >> '.ProcessUtils::escapeArgument($runOutputFile).' 2>&1';
        }

        return $command;
    }
}
