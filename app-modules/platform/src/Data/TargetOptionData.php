<?php

declare(strict_types=1);

namespace Konventix\Platform\Data;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * One thing a console picker may point at (ADR-0066, spec §7.3, D-CPL-11).
 *
 * Four fields and no meaning: a `kind` the owning module chose, the `id`
 * that names the thing inside that kind, a `label` a person can read, and an
 * optional `group` a picker may head its options with (an event's name above
 * its registration types, for instance).
 *
 * Platform names no policy and no binding here. The registry is a generic
 * "name these ids for a picker", which is exactly what keeps Compliance from
 * reading Event's or Registration's tables to render a chip.
 *
 * `id` is a string rather than a ULID type because one kind — a settlement
 * provider — is keyed by its own provider key (`stripe`, `mollie`) and is not
 * an entity at all (D-CPL-02).
 *
 * In `src/Data/` rather than beside `Targets\TargetDirectory`, because that
 * is the one directory `TypeScriptTransformerServiceProvider` scans: a Data
 * class the console renders has to reach `generated.d.ts`, and hand-writing
 * the shape in the page instead is what ADR-0009 forbids.
 */
#[TypeScript]
final class TargetOptionData extends Data
{
    public function __construct(
        public readonly string $kind,
        public readonly string $id,
        public readonly string $label,
        public readonly ?string $group = null,
    ) {}
}
