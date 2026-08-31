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

/**
 * A worker whose timeout reaches the connection's `retry_after` loses the
 * race: Redis releases the job back onto the queue while the first worker is
 * still inside it, a second worker picks it up, and a transactional mail goes
 * out twice. Horizon's own default timeout is 60, so an undeclared one counts
 * as 60 here.
 */
test('no horizon supervisor outlives its connection retry_after', function () {
    $supervisors = config('horizon.defaults');

    expect($supervisors)->not->toBeEmpty();

    foreach ($supervisors as $name => $supervisor) {
        $connection = $supervisor['connection'];
        $retryAfter = config("queue.connections.{$connection}.retry_after");

        expect($retryAfter)->toBeInt("Supervisor [{$name}] uses connection [{$connection}], which declares no retry_after.");

        expect($supervisor['timeout'] ?? 60)->toBeLessThan(
            $retryAfter,
            "Supervisor [{$name}] may run longer than connection [{$connection}] waits before retrying the job — it would be delivered twice.",
        );
    }
});
