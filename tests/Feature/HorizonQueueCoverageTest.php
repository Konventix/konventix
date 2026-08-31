<?php

declare(strict_types=1);

use Symfony\Component\Finder\Finder;

/**
 * ADR-0049 §7: a queue named by any job must be worked by a Horizon
 * supervisor, or its jobs are silently never run under the production
 * topology. This is the test that would have caught the `messaging`
 * queue gap the day the first mail job was written.
 */
test('every queue named by onQueue() has a horizon supervisor', function () {
    $supervised = collect(config('horizon.defaults'))
        ->flatMap(fn (array $supervisor): array => $supervisor['queue'])
        ->all();

    $named = [];

    $moduleSrcDirs = glob(base_path('app-modules/*/src'), GLOB_ONLYDIR) ?: [];

    $files = Finder::create()
        ->files()
        ->in([base_path('app'), ...$moduleSrcDirs])
        ->name('*.php');

    foreach ($files as $file) {
        if (preg_match_all("/->onQueue\\('([^']+)'\\)/", $file->getContents(), $matches)) {
            foreach ($matches[1] as $queue) {
                $named[$queue] = true;
            }
        }
    }

    expect($named)->not->toBeEmpty()
        ->and(array_keys($named))->each->toBeIn($supervised);
});
