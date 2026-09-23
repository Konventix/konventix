<?php

declare(strict_types=1);

namespace Konventix\Competition\Data;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * The event a public tournament page belongs to (design §10), as
 * Competition's own `EventSummary` knows it: the slug every link on the page
 * is built from, the name it is headed with, and the ULID a new team is
 * started at (teams are per event, D-CMP-14).
 */
#[TypeScript]
final class EventRefData extends Data
{
    public function __construct(
        public readonly string $id,
        public readonly string $slug,
        public readonly string $name,
    ) {}
}
