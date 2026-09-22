<?php

declare(strict_types=1);

use Konventix\Agenda\Data\AgendaItemData;

/**
 * Shared fixtures for the Agenda tests. Every function here is
 * process-global and loaded for every worker from `tests/Pest.php`
 * (.ai/rules/tests.md): a helper a second test file calls cannot live
 * inside a `*Test.php`, because under `--parallel` the worker running that
 * second file never loads the first. Prefixed `agenda` to stay apart from
 * every other module's Helpers.php.
 */

/**
 * The payload of one programme entry, with the common both-ends shape as
 * the default. Deliberately `from()` and not `validateAndCreate()`: a test
 * after a reversed or impossible time needs to hand the Action something
 * the form would have refused.
 *
 * @param  array<string, mixed>  $overrides
 */
function agendaItemData(array $overrides = []): AgendaItemData
{
    return AgendaItemData::from(array_merge([
        'id' => null, 'title' => 'Lötkurs', 'description' => null,
        'trackId' => null, 'locationId' => null, 'locationNote' => null, 'speaker' => null,
        'startsAt' => '2026-11-20T15:00:00+01:00', 'endsAt' => '2026-11-20T16:30:00+01:00',
    ], $overrides));
}
