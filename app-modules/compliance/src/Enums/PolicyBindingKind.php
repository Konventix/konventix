<?php

declare(strict_types=1);

namespace Konventix\Compliance\Enums;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * What a policy may be bound to (ADR-0066, D-CPL-02, spec §5.1).
 *
 * Four kinds, and the database stores the value in one `kind` column beside
 * one `target_key` string. Three of them name an entity by ULID; the fourth
 * names a settlement provider by its own key (`stripe`, `mollie`, `unzer`,
 * `manual`), because a provider is a key on `settlement_accounts` and not an
 * entity at all.
 *
 * Compliance never learns what any of these *is*. It stores the kind and the
 * key, matches them against a `BindingContext` of scalars, and asks
 * Platform's target registry for a label when the console has to print one
 * (D-CPL-11). No module is imported and no foreign table is read
 * (ADR-0004).
 *
 * The value is the metric label of `policy_binding_matches_total{kind}` —
 * bounded, unlike the target key it sits beside (T-11).
 */
#[TypeScript]
enum PolicyBindingKind: string
{
    /** An Event's ULID. */
    case Event = 'event';

    /** A RegistrationType's ULID. */
    case RegistrationType = 'registration_type';

    /** A settlement provider's key, not a ULID. */
    case SettlementProvider = 'settlement_provider';

    /** An entry condition's ULID (Registration's, ADR-0066). */
    case EntryCondition = 'entry_condition';

    /**
     * Whether this kind's `target_key` names an entity by ULID. The console
     * type-checks with it; the database deliberately does not (D-CPL-02).
     */
    public function isEntity(): bool
    {
        return $this !== self::SettlementProvider;
    }
}
