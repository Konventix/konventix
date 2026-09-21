<?php

declare(strict_types=1);

use Illuminate\Support\Str;
use Konventix\Compliance\Bindings\BindingContext;
use Konventix\Compliance\Bindings\PolicyResolver;
use Konventix\Compliance\Bindings\ResolvedLegalText;
use Konventix\Compliance\Enums\LawfulBasis;
use Konventix\Compliance\Enums\PolicyBindingKind;
use Konventix\Compliance\Models\Policy;
use Konventix\Compliance\Models\PolicyBinding;
use Konventix\Compliance\Models\PolicyVersion;
use Konventix\Compliance\Support\CheckoutLegalTexts;

/**
 * The centre of ADR-0066's foundation (spec §5.4, D-CPL-03/04/08).
 *
 * The first case is the whole point of the slice: an **unbound policy
 * resolves exactly as it does today**. Everything else in this file is about
 * what bindings add on top of that, and none of it may change the first
 * answer.
 *
 * The matching rule under test is **OR within a kind, AND across kinds**: a
 * policy bound to two events applies at either of them, a policy bound to an
 * event *and* a provider applies only where both hold, a kind the policy
 * does not bind on places no requirement, and a bound kind the context
 * carries nothing for can never match.
 *
 * Helper names prefixed `resolver*`: Pest helpers are process-global
 * (`.ai/rules/tests.md`).
 */
function resolverPolicy(string $slug, array $versionAttributes = []): Policy
{
    $policy = Policy::factory()->create(['slug' => $slug, 'name' => ucfirst($slug)]);

    PolicyVersion::factory()->for($policy)->published()->create($versionAttributes);

    return $policy;
}

/** @return list<string> */
function resolverSlugs(array $texts): array
{
    return array_map(fn (ResolvedLegalText $text): string => $text->slug, $texts);
}

it('resolves an unbound policy exactly as the brand-wide call does', function (): void {
    $brand = $this->withBrand();
    resolverPolicy('terms');
    resolverPolicy('withdrawal', ['requires_consent' => false]);

    $context = BindingContext::forBrand($brand->getKey());
    $texts = app(PolicyResolver::class)->textsFor($context);

    $brandWide = (new CheckoutLegalTexts)->forBrand($brand->getKey());

    // The same two texts, in the same order, with the same versions and
    // links — the no-regression case.
    expect(resolverSlugs($texts))->toBe(['terms', 'withdrawal'])
        ->and(array_column($brandWide, 'slug'))->toBe(['terms', 'withdrawal'])
        ->and($texts[0]->versionId)->toBe($brandWide[0]['versionId'])
        ->and($texts[0]->url)->toBe($brandWide[0]['url'])
        ->and($texts[1]->versionId)->toBe($brandWide[1]['versionId'])
        ->and($texts[0]->bindingIds)->toBe([])
        ->and($texts[0]->isBound())->toBeFalse();
});

it('resolves an unbound policy even for a context that knows nothing but the brand', function (): void {
    $brand = $this->withBrand();
    resolverPolicy('terms');

    // A login knows no event, no type and no provider. An unbound policy
    // still applies there — that is D-CPL-04.
    $texts = app(PolicyResolver::class)->textsFor(BindingContext::forBrand($brand->getKey()));

    expect(resolverSlugs($texts))->toBe(['terms']);
});

it('shows a type-bound policy only for that type', function (): void {
    $brand = $this->withBrand();
    $addendum = resolverPolicy('student-addendum');
    $typeId = (string) Str::ulid();

    PolicyBinding::factory()->for($addendum, 'policy')->toRegistrationType($typeId)->create();

    $resolver = app(PolicyResolver::class);

    $matching = $resolver->textsFor(new BindingContext(
        brandId: $brand->getKey(),
        registrationTypeIds: [$typeId, (string) Str::ulid()],
    ));

    $other = $resolver->textsFor(new BindingContext(
        brandId: $brand->getKey(),
        registrationTypeIds: [(string) Str::ulid()],
    ));

    expect(resolverSlugs($matching))->toBe(['student-addendum'])
        ->and($matching[0]->isBound())->toBeTrue()
        ->and(resolverSlugs($other))->toBe([]);
});

it('shows a provider-bound policy only for that provider', function (): void {
    $brand = $this->withBrand();
    $addendum = resolverPolicy('stripe-addendum');

    PolicyBinding::factory()->for($addendum, 'policy')->toSettlementProvider('stripe')->create();

    $resolver = app(PolicyResolver::class);

    expect(resolverSlugs($resolver->textsFor(new BindingContext(
        brandId: $brand->getKey(),
        settlementProvider: 'stripe',
    ))))->toBe(['stripe-addendum'])
        ->and(resolverSlugs($resolver->textsFor(new BindingContext(
            brandId: $brand->getKey(),
            settlementProvider: 'mollie',
        ))))->toBe([]);
});

it('treats two bindings of one kind as alternatives', function (): void {
    $brand = $this->withBrand();
    $houseRules = resolverPolicy('house-rules');
    $summer = (string) Str::ulid();
    $winter = (string) Str::ulid();

    PolicyBinding::factory()->for($houseRules, 'policy')->toEvent($summer)->create();
    PolicyBinding::factory()->for($houseRules, 'policy')->toEvent($winter)->create();

    $resolver = app(PolicyResolver::class);

    expect(resolverSlugs($resolver->textsFor(new BindingContext($brand->getKey(), eventId: $summer))))->toBe(['house-rules'])
        ->and(resolverSlugs($resolver->textsFor(new BindingContext($brand->getKey(), eventId: $winter))))->toBe(['house-rules'])
        ->and(resolverSlugs($resolver->textsFor(new BindingContext($brand->getKey(), eventId: (string) Str::ulid()))))->toBe([]);
});

it('requires every bound kind to match when a policy binds on more than one', function (): void {
    $brand = $this->withBrand();
    $addendum = resolverPolicy('stripe-at-the-big-event');
    $bigEvent = (string) Str::ulid();

    // "Our Stripe addendum, but only at the big event" — the case the
    // operator chose AND-across-kinds to be able to express (D-CPL-03).
    PolicyBinding::factory()->for($addendum, 'policy')->toEvent($bigEvent)->create();
    PolicyBinding::factory()->for($addendum, 'policy')->toSettlementProvider('stripe')->create();

    $resolver = app(PolicyResolver::class);

    $both = $resolver->textsFor(new BindingContext($brand->getKey(), eventId: $bigEvent, settlementProvider: 'stripe'));
    $eventOnly = $resolver->textsFor(new BindingContext($brand->getKey(), eventId: $bigEvent, settlementProvider: 'mollie'));
    $providerOnly = $resolver->textsFor(new BindingContext($brand->getKey(), eventId: (string) Str::ulid(), settlementProvider: 'stripe'));

    expect(resolverSlugs($both))->toBe(['stripe-at-the-big-event'])
        // Both bindings are reported as the ones that matched.
        ->and($both[0]->bindingIds)->toHaveCount(2)
        ->and(resolverSlugs($eventOnly))->toBe([])
        ->and(resolverSlugs($providerOnly))->toBe([]);
});

it('never matches a bound kind the context carries nothing for', function (): void {
    $brand = $this->withBrand();
    $addendum = resolverPolicy('stripe-addendum');

    PolicyBinding::factory()->for($addendum, 'policy')->toSettlementProvider('stripe')->create();

    // A login, or step one of the checkout: no provider chosen yet.
    $texts = app(PolicyResolver::class)->textsFor(BindingContext::forBrand($brand->getKey()));

    expect(resolverSlugs($texts))->toBe([]);
});

it('resolves an entry-condition binding from the conditions the context carries', function (): void {
    $brand = $this->withBrand();
    $studentTerms = resolverPolicy('student-terms');
    $conditionId = (string) Str::ulid();

    PolicyBinding::factory()->for($studentTerms, 'policy')->toEntryCondition($conditionId)->create();

    $resolver = app(PolicyResolver::class);

    expect(resolverSlugs($resolver->textsFor(new BindingContext(
        brandId: $brand->getKey(),
        entryConditionIds: [$conditionId],
    ))))->toBe(['student-terms'])
        ->and(resolverSlugs($resolver->textsFor(new BindingContext(
            brandId: $brand->getKey(),
            entryConditionIds: [(string) Str::ulid()],
        ))))->toBe([]);
});

it('resolves nothing for a bound policy with no published effective version', function (): void {
    $brand = $this->withBrand();
    $eventId = (string) Str::ulid();

    // Drafted, never published: a draft is not a legal text.
    $draft = Policy::factory()->create(['slug' => 'draft-rules', 'name' => 'Draft rules']);
    PolicyVersion::factory()->for($draft)->create();
    PolicyBinding::factory()->for($draft, 'policy')->toEvent($eventId)->create();

    // Published, but not effective until next month.
    $future = Policy::factory()->create(['slug' => 'future-rules', 'name' => 'Future rules']);
    PolicyVersion::factory()->for($future)->published()->create(['effective_at' => now()->addMonth()]);
    PolicyBinding::factory()->for($future, 'policy')->toEvent($eventId)->create();

    $texts = app(PolicyResolver::class)->textsFor(new BindingContext($brand->getKey(), eventId: $eventId));

    expect(resolverSlugs($texts))->toBe([]);
});

it('lets a brand shadow an operator-wide policy and binds either scope', function (): void {
    $brand = $this->withBrand();
    $eventId = (string) Str::ulid();

    $operatorTerms = Policy::factory()->operatorWide()->create(['slug' => 'terms', 'name' => 'Operator terms']);
    PolicyVersion::factory()->for($operatorTerms)->operatorWide()->published()->create();

    $operatorPrivacy = Policy::factory()->operatorWide()->create(['slug' => 'privacy', 'name' => 'Operator privacy']);
    PolicyVersion::factory()->for($operatorPrivacy)->operatorWide()->published()->create();

    // The brand publishes its own `terms`, which shadows the operator's.
    $brandTerms = resolverPolicy('terms');

    // A brand may bind the operator's inherited text to its own event
    // (D-CPL-05); the binding then carries the brand.
    PolicyBinding::factory()->for($operatorPrivacy, 'policy')->toEvent($eventId)->create();

    $resolver = app(PolicyResolver::class);

    $atTheEvent = $resolver->textsFor(new BindingContext($brand->getKey(), eventId: $eventId));
    $elsewhere = $resolver->textsFor(BindingContext::forBrand($brand->getKey()));

    expect(resolverSlugs($atTheEvent))->toBe(['terms', 'privacy'])
        ->and($atTheEvent[0]->versionId)->toBe((string) $brandTerms->currentVersion()?->getKey())
        ->and($atTheEvent[0]->scope)->toBe('brand')
        ->and($atTheEvent[1]->scope)->toBe('operator')
        // The bound operator-wide text does not follow the brand off its
        // own event.
        ->and(resolverSlugs($elsewhere))->toBe(['terms']);
});

it('inherits an operator-wide binding on an operator-wide policy', function (): void {
    $brand = $this->withBrand();
    $eventId = (string) Str::ulid();

    $operatorRules = Policy::factory()->operatorWide()->create(['slug' => 'house-rules', 'name' => 'House rules']);
    PolicyVersion::factory()->for($operatorRules)->operatorWide()->published()->create();

    // The operator binds globally: the binding carries null too.
    PolicyBinding::factory()->for($operatorRules, 'policy')->operatorWide()->toEvent($eventId)->create();

    $resolver = app(PolicyResolver::class);

    expect(resolverSlugs($resolver->textsFor(new BindingContext($brand->getKey(), eventId: $eventId))))->toBe(['house-rules'])
        ->and(resolverSlugs($resolver->textsFor(BindingContext::forBrand($brand->getKey()))))->toBe([]);
});

it('orders terms and withdrawal first and everything else by name', function (): void {
    $brand = $this->withBrand();
    resolverPolicy('zebra-rules', ['requires_consent' => false]);
    resolverPolicy('withdrawal', ['requires_consent' => false]);
    resolverPolicy('alpha-rules');
    resolverPolicy('terms');

    $texts = app(PolicyResolver::class)->textsFor(BindingContext::forBrand($brand->getKey()));

    expect(resolverSlugs($texts))->toBe(['terms', 'withdrawal', 'alpha-rules', 'zebra-rules']);
});

it('lets requires_consent decide agreement versus information, with no role column', function (): void {
    $brand = $this->withBrand();
    resolverPolicy('terms');
    resolverPolicy('withdrawal', ['requires_consent' => false]);
    resolverPolicy('notice', ['requires_consent' => false]);

    $texts = app(PolicyResolver::class)->textsFor(BindingContext::forBrand($brand->getKey()));

    expect($texts[0]->requiresConsent)->toBeTrue()
        ->and($texts[1]->requiresConsent)->toBeFalse()
        ->and($texts[2]->requiresConsent)->toBeFalse()
        // The two checkout slugs keep the description they already had; a
        // third text plays no checkout role at all (D-CPL-08).
        ->and($texts[0]->role)->not->toBeNull()
        ->and($texts[1]->role)->not->toBeNull()
        ->and($texts[2]->role)->toBeNull();
});

it('answers the outstanding contract question for the same resolution', function (): void {
    $brand = $this->withBrand();
    $eventId = (string) Str::ulid();
    $subjectId = (string) Str::ulid();

    $terms = Policy::factory()->create(['slug' => 'terms', 'name' => 'Terms']);
    PolicyVersion::factory()->for($terms)->published()->create([
        'requires_consent' => true,
        'lawful_basis' => LawfulBasis::Contract,
    ]);
    PolicyBinding::factory()->for($terms, 'policy')->toEvent($eventId)->create();

    $resolver = app(PolicyResolver::class);

    expect($resolver->outstandingContractFor($subjectId, new BindingContext($brand->getKey(), eventId: $eventId)))
        ->toBe(route('policies.required', absolute: false))
        // Away from the event the text binds to, it is not outstanding —
        // the gate asks the same question the resolution does.
        ->and($resolver->outstandingContractFor($subjectId, BindingContext::forBrand($brand->getKey())))
        ->toBeNull();
});

it('does not call a consent-based text an outstanding contract', function (): void {
    $brand = $this->withBrand();
    resolverPolicy('photo-consent', ['requires_consent' => true, 'lawful_basis' => LawfulBasis::Consent]);

    expect(app(PolicyResolver::class)->outstandingContractFor((string) Str::ulid(), BindingContext::forBrand($brand->getKey())))
        ->toBeNull();
});

it('carries the whole context through the bag an event or an order line stores', function (): void {
    $context = new BindingContext(
        brandId: '01J000000000000000000BRAND',
        eventId: '01J000000000000000000EVENT',
        registrationTypeIds: ['01J0000000000000000000TYPE'],
        entryConditionIds: ['01J0000000000000000000COND'],
        settlementProvider: 'stripe',
        registrationId: '01J00000000000000000000REG',
        orderId: '01J000000000000000000ORDER',
    );

    $roundTripped = BindingContext::ofArray($context->toArray());

    expect($roundTripped)->toEqual($context)
        // A bag written by an older release carries fewer keys; the missing
        // ones read as "not known", never as a crash.
        ->and(BindingContext::ofArray(['brand_id' => 'b'])->registrationTypeIds)->toBe([])
        ->and(BindingContext::ofArray([], brandId: 'b')->brandId)->toBe('b')
        ->and(BindingContext::ofArray(['brand_id' => 'b', 'registration_type_ids' => 'nonsense'])->registrationTypeIds)->toBe([]);
});

it('counts one binding match per matched binding, labelled by kind', function (): void {
    $brand = $this->withBrand();
    $addendum = resolverPolicy('stripe-at-the-big-event');
    $eventId = (string) Str::ulid();

    PolicyBinding::factory()->for($addendum, 'policy')->toEvent($eventId)->create();
    PolicyBinding::factory()->for($addendum, 'policy')->toSettlementProvider('stripe')->create();

    app(PolicyResolver::class)->textsFor(new BindingContext($brand->getKey(), eventId: $eventId, settlementProvider: 'stripe'));

    config()->set('health.endpoint_secret', 'metrics-test-secret');
    $scrape = $this->withHeader('Authorization', 'Bearer metrics-test-secret')->get('/metrics')->assertOk()->getContent();

    expect($scrape)
        ->toContain('konventix_policy_binding_matches_total{brand="'.$brand->slug.'",kind="event"} 1')
        ->toContain('konventix_policy_binding_matches_total{brand="'.$brand->slug.'",kind="settlement_provider"} 1')
        // The target key is never a label (T-11).
        ->not->toContain($eventId);
});

it('never counts a match for an unbound resolution', function (): void {
    $brand = $this->withBrand();
    resolverPolicy('terms');

    app(PolicyResolver::class)->textsFor(BindingContext::forBrand($brand->getKey()));

    config()->set('health.endpoint_secret', 'metrics-test-secret');
    $scrape = $this->withHeader('Authorization', 'Bearer metrics-test-secret')->get('/metrics')->assertOk()->getContent();

    expect($scrape)->not->toContain('konventix_policy_binding_matches_total{');
});

it('knows which kinds name an entity and which names a provider key', function (): void {
    expect(PolicyBindingKind::Event->isEntity())->toBeTrue()
        ->and(PolicyBindingKind::RegistrationType->isEntity())->toBeTrue()
        ->and(PolicyBindingKind::EntryCondition->isEntity())->toBeTrue()
        ->and(PolicyBindingKind::SettlementProvider->isEntity())->toBeFalse();
});
