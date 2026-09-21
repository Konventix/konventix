<?php

declare(strict_types=1);

namespace Konventix\Platform\Targets;

use Konventix\Platform\Data\TargetOptionData;

/**
 * Who can name the ids a console picker offers (ADR-0066 §7.3, D-CPL-11).
 *
 * The binding editor has to show "Event: Summer LAN 2026" and "Type: Student
 * weekend", and Compliance may read neither Event's nor Registration's
 * tables (ADR-0004). So Platform owns a boot-time registry in
 * `Slots\SlotRegistry`'s shape and each module registers a
 * {@see TargetProvider} for the kinds it owns, from its own ServiceProvider
 * (ADR-0003). Compliance reads the registry and never a foreign model.
 *
 * Nothing here names a policy or a binding: the kinds are generic, and the
 * registry is as complete as the modules that registered — which is a state
 * the picker's empty sentence says out loud rather than a bug.
 *
 * Bound `singletonIf` beside `Legal\PublicLegalTexts`, with the empty
 * registry as its own default: a build without Event, Registration or
 * Commerce has fewer kinds, not a missing binding. It holds boot-time
 * registrations only, so a long-lived Octane worker may share it — the
 * providers resolve their own scoped bindings per call, exactly as the slot
 * registry's prop closures do.
 */
class TargetDirectory
{
    /** @var list<TargetProvider> */
    private array $providers = [];

    public function register(TargetProvider $provider): void
    {
        $this->providers[] = $provider;
    }

    /**
     * Every kind at least one provider answers for, in registration order.
     *
     * @return list<string>
     */
    public function kinds(): array
    {
        $kinds = [];

        foreach ($this->providers as $provider) {
            foreach ($provider->kinds() as $kind) {
                if (! in_array($kind, $kinds, true)) {
                    $kinds[] = $kind;
                }
            }
        }

        return $kinds;
    }

    public function hasAny(): bool
    {
        return $this->providers !== [];
    }

    /**
     * Every option a picker may offer for this brand — null asks for the
     * operator-wide set, which is what the admin host wants (ADR-0029).
     *
     * @return list<TargetOptionData>
     */
    public function optionsFor(?string $brandId): array
    {
        $options = [];

        foreach ($this->providers as $provider) {
            $options = [...$options, ...$provider->optionsFor($brandId)];
        }

        return $options;
    }

    /**
     * The label for one target, or null when no provider can name it.
     *
     * Null rather than a throw, for the case this registry exists to make
     * renderable: a binding whose target has since been deleted (spec §9.2).
     */
    public function labelFor(string $kind, string $id): ?string
    {
        foreach ($this->providers as $provider) {
            if (! in_array($kind, $provider->kinds(), true)) {
                continue;
            }

            $label = $provider->labelFor($kind, $id);

            if ($label !== null) {
                return $label;
            }
        }

        return null;
    }
}
