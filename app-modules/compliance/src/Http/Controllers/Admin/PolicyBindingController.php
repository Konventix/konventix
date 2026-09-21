<?php

declare(strict_types=1);

namespace Konventix\Compliance\Http\Controllers\Admin;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Konventix\Compliance\Actions\BindPolicy;
use Konventix\Compliance\Actions\UnbindPolicy;
use Konventix\Compliance\Enums\Permission;
use Konventix\Compliance\Enums\PolicyBindingKind;
use Konventix\Compliance\Models\Policy;
use Konventix\Compliance\Models\PolicyBinding;

/**
 * The "Where this applies" card's two writes (ADR-0066 §9.2, D-CPL-16).
 *
 * Its own capability, `compliance.manage-policy-bindings`, and deliberately
 * not `ManagePolicies`: binding a text to a product changes who is legally
 * bound by it without changing a word of it, which is a different act from
 * editing the text and often a different person.
 *
 * The target is never validated against another module's table — Compliance
 * stores a `kind` and a key and learns what neither means (ADR-0004). The
 * picker is fed by Platform's `TargetDirectory`, and a target that stops
 * existing simply stops matching (spec §9.2); the console then renders it as
 * `Unknown (event · 01HX…)` with a *Remove* button, which is the only place
 * it can be fixed.
 *
 * Both endpoints escape `BrandScope`: the console runs on the operator admin
 * host, where the scope hardens every brand-owned query to zero rows
 * (ADR-0029), and an operator-wide policy carries no brand at all (#76).
 */
class PolicyBindingController
{
    public function store(Request $request, string $policy, BindPolicy $bindPolicy): RedirectResponse
    {
        Gate::authorize(Permission::ManagePolicyBindings->value);

        /** @var Policy $model */
        $model = Policy::withoutBrandScope()->findOrFail($policy);

        $validated = $request->validate([
            'kind' => ['required', Rule::enum(PolicyBindingKind::class)],
            // One column for four kinds (D-CPL-02), so the length is the
            // column's and the shape is the console's business: an entity
            // kind takes a ULID, a settlement provider takes its own key.
            'target_key' => ['required', 'string', 'max:64'],
        ]);

        $kind = PolicyBindingKind::from($validated['kind']);

        if ($kind->isEntity() && ! str($validated['target_key'])->isUlid()) {
            return back()->withErrors(['target_key' => __('Choose one of the offered targets.')]);
        }

        $bindPolicy(
            $model,
            $kind,
            $validated['target_key'],
            // The binding inherits the policy's own scope (D-CPL-05): the
            // operator's text binds globally with a null brand, a brand's own
            // text binds for that brand. A brand narrowing the operator's
            // inherited text to its own event is the case the column exists
            // for, and it is not reachable from this console — which only
            // ever edits one policy row at a time.
            $model->brand_id,
            (string) $request->user()->getAuthIdentifier(),
        );

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Binding added.')]);

        return to_route('admin.policies.edit', $model->getKey());
    }

    public function destroy(Request $request, string $binding, UnbindPolicy $unbindPolicy): RedirectResponse
    {
        Gate::authorize(Permission::ManagePolicyBindings->value);

        /** @var PolicyBinding $model */
        $model = PolicyBinding::withoutBrandScope()->findOrFail($binding);
        $policyId = (string) $model->policy_id;

        $unbindPolicy($model, (string) $request->user()->getAuthIdentifier());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Binding removed.')]);

        return to_route('admin.policies.edit', $policyId);
    }
}
