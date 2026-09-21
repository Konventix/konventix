<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Konventix\Compliance\Actions\BindPolicy;
use Konventix\Compliance\Actions\UnbindPolicy;
use Konventix\Compliance\Bindings\BindingContext;
use Konventix\Compliance\Bindings\PolicyResolver;
use Konventix\Compliance\Enums\PolicyBindingKind;
use Konventix\Compliance\Events\PolicyBindingRemoved;
use Konventix\Compliance\Events\PolicyBound;
use Konventix\Compliance\Models\AuditLogEntry;
use Konventix\Compliance\Models\Policy;
use Konventix\Compliance\Models\PolicyBinding;
use Konventix\Compliance\Models\PolicyVersion;
use Konventix\Platform\Models\Brand;

/**
 * Binding and unbinding a policy (ADR-0066, D-CPL-02/05/16).
 *
 * What a binding *means* is `PolicyResolverTest`'s subject; this file is
 * about the rows and the events: one per target, an operator-wide scope that
 * stays null, an audit entry for a privileged act, and a binding whose
 * target no longer exists quietly never matching.
 *
 * Helper names prefixed `binding*`: Pest helpers are process-global
 * (`.ai/rules/tests.md`).
 */
function bindingPublishedPolicy(string $slug = 'house-rules'): Policy
{
    $policy = Policy::factory()->create(['slug' => $slug, 'name' => ucfirst($slug)]);
    PolicyVersion::factory()->for($policy)->published()->create();

    return $policy;
}

it('binds a policy to each kind and stores the target as one key', function (): void {
    $brand = $this->withBrand();
    $policy = bindingPublishedPolicy();
    $bind = new BindPolicy;

    $eventId = (string) Str::ulid();
    $typeId = (string) Str::ulid();
    $conditionId = (string) Str::ulid();

    $bind($policy, PolicyBindingKind::Event, $eventId, $brand->getKey());
    $bind($policy, PolicyBindingKind::RegistrationType, $typeId, $brand->getKey());
    $bind($policy, PolicyBindingKind::EntryCondition, $conditionId, $brand->getKey());
    // The one kind whose key is not a ULID (D-CPL-02).
    $bind($policy, PolicyBindingKind::SettlementProvider, 'stripe', $brand->getKey());

    $bindings = $policy->bindings()->get();

    expect($bindings)->toHaveCount(4)
        ->and($bindings->pluck('target_key')->sort()->values()->all())
        ->toEqualCanonicalizing([$eventId, $typeId, $conditionId, 'stripe'])
        ->and($bindings->pluck('kind')->map(fn (PolicyBindingKind $kind): string => $kind->value)->all())
        ->toEqualCanonicalizing(['event', 'registration_type', 'entry_condition', 'settlement_provider'])
        ->and($bindings->every(fn (PolicyBinding $binding): bool => $binding->brand_id === $brand->getKey()))->toBeTrue();
});

it('returns the binding already on record instead of writing a second row', function (): void {
    $brand = $this->withBrand();
    $policy = bindingPublishedPolicy();
    $eventId = (string) Str::ulid();
    $bind = new BindPolicy;

    $first = $bind($policy, PolicyBindingKind::Event, $eventId, $brand->getKey());
    $second = $bind($policy, PolicyBindingKind::Event, $eventId, $brand->getKey());

    expect($second->getKey())->toBe($first->getKey())
        ->and($policy->bindings()->count())->toBe(1);
});

it('keeps an operator-wide binding operator-wide', function (): void {
    $this->withBrand();
    $policy = Policy::factory()->operatorWide()->create(['slug' => 'terms', 'name' => 'Terms']);
    PolicyVersion::factory()->for($policy)->operatorWide()->published()->create();

    $binding = (new BindPolicy)($policy, PolicyBindingKind::Event, (string) Str::ulid(), null);

    expect($binding->brand_id)->toBeNull()
        ->and($binding->isOperatorWide())->toBeTrue();
});

it('lets a brand bind the operator-wide text to its own event', function (): void {
    $brand = $this->withBrand();
    $policy = Policy::factory()->operatorWide()->create(['slug' => 'terms', 'name' => 'Terms']);
    PolicyVersion::factory()->for($policy)->operatorWide()->published()->create();
    $eventId = (string) Str::ulid();

    $binding = (new BindPolicy)($policy, PolicyBindingKind::Event, $eventId, $brand->getKey());

    expect($binding->brand_id)->toBe($brand->getKey())
        ->and($binding->isOperatorWide())->toBeFalse()
        ->and(app(PolicyResolver::class)->textsFor(new BindingContext($brand->getKey(), eventId: $eventId)))
        ->toHaveCount(1);
});

it('emits PolicyBound with the SPEC scalar payload', function (): void {
    $brand = $this->withBrand();
    $policy = bindingPublishedPolicy();
    $eventId = (string) Str::ulid();
    $actorId = (string) Str::ulid();

    Event::fake([PolicyBound::class, PolicyBindingRemoved::class]);

    $binding = (new BindPolicy)($policy, PolicyBindingKind::Event, $eventId, $brand->getKey(), $actorId);

    Event::assertDispatched(
        PolicyBound::class,
        fn (PolicyBound $event): bool => $event->policyId === $policy->getKey()
            && $event->bindingId === $binding->getKey()
            && $event->brandId === $brand->getKey()
            && $event->kind === 'event'
            && $event->targetKey === $eventId
            && $event->changedByUserId === $actorId,
    );
});

it('audits binding and unbinding as privileged acts', function (): void {
    $brand = $this->withBrand();
    $policy = bindingPublishedPolicy();
    $eventId = (string) Str::ulid();
    $actorId = (string) Str::ulid();

    $binding = (new BindPolicy)($policy, PolicyBindingKind::Event, $eventId, $brand->getKey(), $actorId);
    $bindingId = (string) $binding->getKey();

    (new UnbindPolicy)($binding, $actorId);

    $actions = AuditLogEntry::query()
        ->where('subject_id', $bindingId)
        ->pluck('action')
        ->all();

    expect($actions)->toEqualCanonicalizing([
        'compliance.policy.bound',
        'compliance.policy.binding-removed',
    ]);
});

it('widens a policy back to the whole brand when its last binding goes', function (): void {
    $brand = $this->withBrand();
    $policy = bindingPublishedPolicy();
    $eventId = (string) Str::ulid();

    $binding = (new BindPolicy)($policy, PolicyBindingKind::Event, $eventId, $brand->getKey());

    $resolver = app(PolicyResolver::class);

    expect($resolver->textsFor(BindingContext::forBrand($brand->getKey())))->toBe([]);

    (new UnbindPolicy)($binding);

    // Removing the last binding widens, it does not narrow (D-CPL-04).
    expect($resolver->textsFor(BindingContext::forBrand($brand->getKey())))->toHaveCount(1)
        ->and(PolicyBinding::withoutBrandScope()->count())->toBe(0);
});

it('emits PolicyBindingRemoved with the payload of the binding that is gone', function (): void {
    $brand = $this->withBrand();
    $policy = bindingPublishedPolicy();
    $eventId = (string) Str::ulid();
    $binding = (new BindPolicy)($policy, PolicyBindingKind::Event, $eventId, $brand->getKey());
    $bindingId = (string) $binding->getKey();

    Event::fake([PolicyBound::class, PolicyBindingRemoved::class]);

    (new UnbindPolicy)($binding);

    Event::assertDispatched(
        PolicyBindingRemoved::class,
        fn (PolicyBindingRemoved $event): bool => $event->bindingId === $bindingId
            && $event->policyId === $policy->getKey()
            && $event->brandId === $brand->getKey()
            && $event->kind === 'event'
            && $event->targetKey === $eventId,
    );
});

it('lets a dangling binding never match rather than fail', function (): void {
    $brand = $this->withBrand();
    $policy = bindingPublishedPolicy();

    // A type that was deleted, a provider that was disconnected: the key
    // stays, the target does not exist, and Compliance never checked.
    (new BindPolicy)($policy, PolicyBindingKind::RegistrationType, (string) Str::ulid(), $brand->getKey());

    $texts = app(PolicyResolver::class)->textsFor(new BindingContext(
        brandId: $brand->getKey(),
        registrationTypeIds: [(string) Str::ulid()],
    ));

    expect($texts)->toBe([]);
});

it('cascades a policy\'s bindings away with the policy', function (): void {
    $brand = $this->withBrand();
    $policy = bindingPublishedPolicy();

    (new BindPolicy)($policy, PolicyBindingKind::Event, (string) Str::ulid(), $brand->getKey());

    $policy->delete();

    expect(PolicyBinding::withoutBrandScope()->count())->toBe(0);
});

it('does not let one brand narrow an inherited text for another', function (): void {
    $otherBrand = Brand::factory()->create();
    $brand = $this->withBrand();

    $operatorRules = Policy::factory()->operatorWide()->create(['slug' => 'house-rules', 'name' => 'House rules']);
    PolicyVersion::factory()->for($operatorRules)->operatorWide()->published()->create();

    // The *other* brand binds the operator's inherited text to its own
    // event (D-CPL-05). That narrows the text for that brand and for
    // nobody else — this brand still reads it everywhere, which is what an
    // inherited text with no binding of its own means (D-CPL-04).
    PolicyBinding::factory()->for($operatorRules, 'policy')->toEvent((string) Str::ulid())->create([
        'brand_id' => $otherBrand->getKey(),
    ]);

    $texts = app(PolicyResolver::class)->textsFor(BindingContext::forBrand($brand->getKey()));

    expect(array_map(fn ($text): string => $text->slug, $texts))->toBe(['house-rules']);
});
