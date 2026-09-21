<?php

declare(strict_types=1);

namespace Konventix\Compliance\Actions;

use Illuminate\Support\Facades\DB;
use Konventix\Compliance\Enums\PolicyBindingKind;
use Konventix\Compliance\Events\PolicyBound;
use Konventix\Compliance\Models\Policy;
use Konventix\Compliance\Models\PolicyBinding;

/**
 * Binds a policy to something it governs (ADR-0066, D-CPL-02/05).
 *
 * Binding the **same** target twice returns the binding already on record
 * rather than writing a second row: the console's picker can be
 * double-submitted, and two rows saying the same thing would make "who bound
 * this, and when" ambiguous and audit it twice. The unique index is the
 * backstop; this is the answer that does not throw at a person.
 *
 * `brandId` decides the scope, and null is a deliberate value, not an
 * omission (D-CPL-05, #76): the operator binding its own text globally
 * passes null, a brand binding the operator's inherited text to its own
 * event passes its brand. The caller says which — see `MayBeOperatorWide`
 * for why the model cannot guess.
 *
 * The target is not validated against anything. Compliance stores a `kind`
 * and a key and never learns what either means (ADR-0004); a binding whose
 * target does not exist, or stops existing, simply never matches, and the
 * console warns about it (spec §9.2).
 */
final class BindPolicy
{
    public function __invoke(
        Policy $policy,
        PolicyBindingKind $kind,
        string $targetKey,
        ?string $brandId,
        ?string $changedByUserId = null,
    ): PolicyBinding {
        return DB::transaction(function () use ($policy, $kind, $targetKey, $brandId, $changedByUserId): PolicyBinding {
            // Escapes BrandScope: an operator-wide binding carries no
            // `brand_id` at all, and the console asks this from the operator
            // admin host where the scope hardens to zero rows (ADR-0029,
            // .ai/rules/app-modules-src.md).
            $existing = PolicyBinding::withoutBrandScope()
                ->where('policy_id', $policy->getKey())
                ->where('kind', $kind)
                ->where('target_key', $targetKey)
                ->first();

            if ($existing !== null) {
                return $existing;
            }

            $binding = new PolicyBinding([
                'policy_id' => $policy->getKey(),
                'kind' => $kind,
                'target_key' => $targetKey,
                'created_by_user_id' => $changedByUserId,
            ]);

            // Force-filled rather than mass-assigned, exactly as
            // `CreatePolicy` does it: passing the key explicitly — null
            // included — is how a caller tells the creating hook this row is
            // deliberately operator-wide rather than missing its brand (see
            // Models\Concerns\MayBeOperatorWide). The console also writes
            // from the operator admin host, where no brand is resolved at
            // all (ADR-0029).
            $binding->forceFill(['brand_id' => $brandId])->save();

            event(new PolicyBound(
                policyId: (string) $policy->getKey(),
                bindingId: (string) $binding->getKey(),
                brandId: $brandId,
                kind: $kind->value,
                targetKey: $targetKey,
                changedByUserId: $changedByUserId,
            ));

            return $binding;
        });
    }
}
