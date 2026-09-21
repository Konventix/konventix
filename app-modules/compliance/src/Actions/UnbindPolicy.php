<?php

declare(strict_types=1);

namespace Konventix\Compliance\Actions;

use Illuminate\Support\Facades\DB;
use Konventix\Compliance\Events\PolicyBindingRemoved;
use Konventix\Compliance\Models\PolicyBinding;

/**
 * Takes a binding off a policy (ADR-0066).
 *
 * A hard delete, unlike a withdrawn consent: a binding is configuration, not
 * evidence. What it was while it stood is on the audit trail, and on every
 * consent row it caused — `consents.policy_binding_id` keeps the id after
 * the row is gone, which is why that column carries no foreign key
 * (D-CPL-09).
 *
 * Removing the **last** binding of a policy widens it back to the whole
 * brand rather than narrowing it to nothing (D-CPL-04). That is the least
 * obvious consequence in this feature; the console says it out loud on the
 * empty state, and `PolicyBindingRemoved` records it.
 */
final class UnbindPolicy
{
    public function __invoke(PolicyBinding $binding, ?string $changedByUserId = null): void
    {
        DB::transaction(function () use ($binding, $changedByUserId): void {
            $policyId = (string) $binding->policy_id;
            $bindingId = (string) $binding->getKey();
            $brandId = $binding->brand_id;
            $kind = $binding->kind->value;
            $targetKey = $binding->target_key;

            $binding->delete();

            event(new PolicyBindingRemoved(
                policyId: $policyId,
                bindingId: $bindingId,
                brandId: $brandId,
                kind: $kind,
                targetKey: $targetKey,
                changedByUserId: $changedByUserId,
            ));
        });
    }
}
