<?php

declare(strict_types=1);

use App\Console\Commands\ScheduleWorkQuietly;
use Illuminate\Support\Facades\Artisan;

/**
 * `schedule:work` streams the child `schedule:run`'s stdout and stderr into
 * one stream, so its "Running [...]" console line glues itself to the JSON
 * error record the child writes and the record stops being parseable in
 * VictoriaLogs (5,232 such lines a day on dev). The application's own
 * schedule:work runs the child quietly; the structured schedule.task
 * records replace the console lines (ADR-0059).
 */
it('replaces the framework schedule:work', function (): void {
    expect(Artisan::all()['schedule:work'])->toBeInstanceOf(ScheduleWorkQuietly::class);
});

it('runs schedule:run with --quiet and keeps the whisper and output-file options', function (): void {
    expect(ScheduleWorkQuietly::scheduleRunCommand(whisper: false, runOutputFile: null))
        ->toEndWith("'artisan' schedule:run --quiet")
        ->and(ScheduleWorkQuietly::scheduleRunCommand(whisper: true, runOutputFile: '/tmp/out.log'))
        ->toContain('schedule:run --quiet --whisper')
        ->toContain(">> '/tmp/out.log' 2>&1");
});
