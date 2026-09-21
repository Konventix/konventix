<?php

declare(strict_types=1);

use Illuminate\Support\Collection;
use Konventix\Compliance\Enums\PolicyBindingKind;
use Konventix\Compliance\Models\Policy;
use Konventix\Compliance\Models\PolicyBinding;
use Konventix\Compliance\Models\PolicyVersion;
use Konventix\Event\Models\Event;

/**
 * The "Where this applies" card on the policy editor (ADR-0066 §9.2,
 * D-CPL-16).
 *
 * What a binding *means* is `PolicyResolverTest`'s subject and how the rows
 * are written is `PolicyBindingTest`'s; this file is the console: the picker
 * fed by Platform's registry, the chips with their labels, the unlabelable
 * target that stays removable, and the capability that is deliberately not
 * `ManagePolicies`.
 *
 * Helper names prefixed `bindingConsole*`: Pest helpers are process-global
 * (`.ai/rules/tests.md`).
 */
function bindingConsolePolicy(string $slug = 'stripe-addendum'): Policy
{
    $policy = Policy::factory()->create(['slug' => $slug, 'name' => 'Stripe addendum']);
    PolicyVersion::factory()->for($policy)->published()->create();

    return $policy;
}

it('feeds the picker from the target registry and says the empty state out loud', function (): void {
    [, $admin] = complianceOperator([
        'platform.access-admin',
        'compliance.view-policies',
        'compliance.manage-policy-bindings',
    ]);
    $this->withBrand();
    $event = Event::factory()->create(['name' => 'Summer LAN 2026']);
    $policy = bindingConsolePolicy();

    $this->actingAs($admin)
        ->get("http://admin.compliance.test/admin/policies/{$policy->getKey()}/edit")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('admin/policies/Edit')
            // D-CPL-04: no binding at all, which means "applies everywhere
            // this brand sells" — the state the card names in words.
            ->where('bindings', [])
            ->where('canManageBindings', true)
            // Every kind is offered, and the options are the registry's
            // (D-CPL-11) — Compliance read no foreign table to build them.
            ->has('bindingKinds', 4)
            ->where('bindingKinds.0.value', 'event')
            ->where('bindingKinds.0.plural', 'events')
            ->has('bindingOptions')
            ->where('bindingOptions', fn (Collection $options): bool => $options
                ->contains(fn (array $option): bool => $option['kind'] === 'event'
                    && $option['id'] === (string) $event->getKey()
                    && $option['label'] === 'Summer LAN 2026')));
});

it('binds a target from the picker and renders it as a labelled chip', function (): void {
    [, $admin] = complianceOperator([
        'platform.access-admin',
        'compliance.view-policies',
        'compliance.manage-policy-bindings',
    ]);
    $brand = $this->withBrand();
    $event = Event::factory()->create(['name' => 'Summer LAN 2026']);
    $policy = bindingConsolePolicy();

    $this->actingAs($admin)
        ->post("http://admin.compliance.test/admin/policies/{$policy->getKey()}/bindings", [
            'kind' => 'event',
            'target_key' => (string) $event->getKey(),
        ])
        ->assertRedirect("http://admin.compliance.test/admin/policies/{$policy->getKey()}/edit");

    $binding = PolicyBinding::withoutBrandScope()->sole();

    expect($binding->kind)->toBe(PolicyBindingKind::Event)
        ->and($binding->target_key)->toBe((string) $event->getKey())
        // D-CPL-05: the binding inherits the policy's own scope.
        ->and($binding->brand_id)->toBe($brand->getKey())
        ->and($binding->created_by_user_id)->toBe((string) $admin->getKey());

    $this->actingAs($admin)
        ->get("http://admin.compliance.test/admin/policies/{$policy->getKey()}/edit")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('bindings', 1)
            ->where('bindings.0.kind', 'event')
            ->where('bindings.0.kindLabel', 'Event')
            ->where('bindings.0.label', 'Summer LAN 2026'));
});

it('renders a target the registry cannot name, and lets it be removed', function (): void {
    // Spec §9.2: a binding whose target was deleted is not an error. It
    // never matches, it reads as `Unknown (event · 01HX…)` in the card, and
    // the console is the one place it can be cleaned up.
    [, $admin] = complianceOperator([
        'platform.access-admin',
        'compliance.view-policies',
        'compliance.manage-policy-bindings',
    ]);
    $this->withBrand();
    $policy = bindingConsolePolicy();
    $event = Event::factory()->create(['name' => 'Cancelled edition']);

    $this->actingAs($admin)
        ->post("http://admin.compliance.test/admin/policies/{$policy->getKey()}/bindings", [
            'kind' => 'event',
            'target_key' => (string) $event->getKey(),
        ]);

    $event->forceDelete();

    $binding = PolicyBinding::withoutBrandScope()->sole();

    $this->actingAs($admin)
        ->get("http://admin.compliance.test/admin/policies/{$policy->getKey()}/edit")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('bindings', 1)
            // Null is the renderable state the page turns into
            // `Unknown (event · …)`; the id is still there to remove.
            ->where('bindings.0.label', null)
            ->where('bindings.0.targetKey', (string) $event->getKey()));

    $this->actingAs($admin)
        ->delete("http://admin.compliance.test/admin/policy-bindings/{$binding->getKey()}")
        ->assertRedirect();

    expect(PolicyBinding::withoutBrandScope()->count())->toBe(0);
});

it('refuses a target key that is not a ULID for an entity kind', function (): void {
    [, $admin] = complianceOperator([
        'platform.access-admin',
        'compliance.view-policies',
        'compliance.manage-policy-bindings',
    ]);
    $this->withBrand();
    $policy = bindingConsolePolicy();

    // The console does the type-checking the one-column design deliberately
    // leaves to it (D-CPL-02).
    $this->actingAs($admin)
        ->post("http://admin.compliance.test/admin/policies/{$policy->getKey()}/bindings", [
            'kind' => 'event',
            'target_key' => 'not-a-ulid',
        ])
        ->assertRedirect()
        ->assertSessionHasErrors('target_key');

    expect(PolicyBinding::withoutBrandScope()->count())->toBe(0);
});

it('refuses both writes without the binding capability, and hides the controls', function (): void {
    // D-CPL-16: binding a text to a product changes who is bound by it
    // without changing a word of it, so `ManagePolicies` is not enough.
    [, $admin] = complianceOperator([
        'platform.access-admin',
        'compliance.view-policies',
        'compliance.manage-policies',
    ]);
    $this->withBrand();
    $event = Event::factory()->create();
    $policy = bindingConsolePolicy();
    $binding = PolicyBinding::factory()->for($policy)->create([
        'kind' => PolicyBindingKind::Event,
        'target_key' => (string) $event->getKey(),
    ]);

    $this->actingAs($admin)
        ->post("http://admin.compliance.test/admin/policies/{$policy->getKey()}/bindings", [
            'kind' => 'event',
            'target_key' => (string) $event->getKey(),
        ])
        ->assertForbidden();

    $this->actingAs($admin)
        ->delete("http://admin.compliance.test/admin/policy-bindings/{$binding->getKey()}")
        ->assertForbidden();

    $this->actingAs($admin)
        ->get("http://admin.compliance.test/admin/policies/{$policy->getKey()}/edit")
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('canManageBindings', false));
});

it('marks a bound policy on the index and leaves an unbound one unmarked', function (): void {
    [, $admin] = complianceOperator();
    $this->withBrand();

    $bound = bindingConsolePolicy('bound-text');
    $unbound = bindingConsolePolicy('unbound-text');
    PolicyBinding::factory()->for($bound)->create([
        'kind' => PolicyBindingKind::SettlementProvider,
        'target_key' => 'stripe',
    ]);

    $this->actingAs($admin)
        ->get('http://admin.compliance.test/admin/policies?sort=slug')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('policies.data', 2)
            ->where('policies.data.0.slug', $bound->slug)
            ->where('policies.data.0.bound', true)
            ->where('policies.data.1.slug', $unbound->slug)
            ->where('policies.data.1.bound', false));
});
