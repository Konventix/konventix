<?php

declare(strict_types=1);

namespace Konventix\Commerce\Targets;

use Konventix\Commerce\Settlement\ProviderLabels;
use Konventix\Commerce\Settlement\SettlementAdapterRegistry;
use Konventix\Platform\Data\TargetOptionData;
use Konventix\Platform\Targets\TargetProvider;

/**
 * Commerce's half of Platform's target registry (ADR-0066 §7.3, D-CPL-11):
 * the `settlement_provider` kind.
 *
 * The only kind whose `target_key` is not a ULID (D-CPL-02) — a provider is
 * a key on `settlement_accounts`, not an entity — so the options come from
 * the adapter registry rather than from a table, and the labels from
 * `Settlement\ProviderLabels`, which is already the one place a provider key
 * is turned into something a person reads (ADR-0048).
 *
 * The same options for every brand, on purpose: which providers a brand has
 * *connected* is an account fact that changes weekly, and a picker that hid
 * `mollie` because nobody has connected it yet would make the binding
 * un-preparable. An unconnected provider's binding simply never matches,
 * exactly like a deleted event's.
 *
 * `labelFor()` answers only for a registered provider. An unknown key comes
 * back null rather than through `ProviderLabels`' `ucfirst` default, because
 * "Bogus" reads like a provider that exists while
 * `Unknown (settlement_provider · bogus)` is the state the console is meant
 * to show (spec §9.2).
 *
 * Commerce learns nothing about policies here: it is asked to name a
 * provider key, and that is all it does.
 */
final class SettlementProviderTargetProvider implements TargetProvider
{
    public const string KIND = 'settlement_provider';

    public function __construct(private readonly SettlementAdapterRegistry $registry) {}

    /**
     * @return list<string>
     */
    public function kinds(): array
    {
        return [self::KIND];
    }

    /**
     * @return list<TargetOptionData>
     */
    public function optionsFor(?string $brandId): array
    {
        $providers = array_keys($this->registry->registered());
        sort($providers);

        return array_values(array_map(
            fn (string $provider): TargetOptionData => new TargetOptionData(
                kind: self::KIND,
                id: $provider,
                label: ProviderLabels::labelFor($provider),
                group: __('Payment providers'),
            ),
            $providers,
        ));
    }

    public function labelFor(string $kind, string $id): ?string
    {
        if ($kind !== self::KIND || ! array_key_exists($id, $this->registry->registered())) {
            return null;
        }

        return ProviderLabels::labelFor($id);
    }
}
