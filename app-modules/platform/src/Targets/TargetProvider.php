<?php

declare(strict_types=1);

namespace Konventix\Platform\Targets;

use Konventix\Platform\Data\TargetOptionData;

/**
 * What one module contributes to {@see TargetDirectory} (ADR-0066 §7.3,
 * D-CPL-11): the kinds it owns, the options a picker may offer for them, and
 * a label for one id.
 *
 * Registered from the owning module's own ServiceProvider
 * (`ModuleServiceProvider::registerTargetProviders()`), the way slots, block
 * types and console event sources already are (ADR-0003). Event registers
 * `event`; Registration registers `registration_type` and `entry_condition`;
 * Commerce registers `settlement_provider`.
 *
 * An implementation answers about *its own* kinds only and returns null or an
 * empty list for anything else — a directory with no provider for a kind is a
 * picker with no options, never an error.
 */
interface TargetProvider
{
    /**
     * The kinds this provider answers for.
     *
     * @return list<string>
     */
    public function kinds(): array;

    /**
     * Every option a picker may offer, for one brand or — null — for the
     * whole operator, which is what the operator console asks (ADR-0029
     * resolves no brand there).
     *
     * @return list<TargetOptionData>
     */
    public function optionsFor(?string $brandId): array;

    /**
     * The label for one id of one kind, or null when this provider does not
     * own the kind or cannot name the id. Null is a renderable state — the
     * console prints `Unknown (event · 01HX…)` — and never an exception:
     * a binding whose target was deleted must stay removable (spec §9.2).
     */
    public function labelFor(string $kind, string $id): ?string;
}
