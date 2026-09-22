<?php

declare(strict_types=1);

namespace Konventix\Agenda\Data;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * The props of the "Jetzt / Gleich" hub card (design §6): what is running
 * at this moment, what starts next, and the way to the whole programme.
 *
 * Both entries are nullable and independently so — before the first item
 * of the day there is only a `next`, after the last one there is neither,
 * and the card says so instead of hiding. `NowNext` decides which item
 * each one is; a cancelled item is never either of them.
 *
 * `programmeUrl` is passed in rather than built here: the public
 * programme lives on Event's `/events/{event:slug}/…` route, and the
 * caller is the one holding the event (ADR-0004).
 */
#[TypeScript]
final class NowNextCardData extends Data
{
    public function __construct(
        public readonly ?ProgrammeCellData $now,
        public readonly ?ProgrammeCellData $next,
        public readonly string $programmeUrl,
    ) {}
}
