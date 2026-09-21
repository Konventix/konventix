<?php

declare(strict_types=1);

use Illuminate\Support\Str;
use Konventix\Compliance\Actions\BindPolicy;
use Konventix\Compliance\Enums\PolicyBindingKind;
use Konventix\Compliance\Models\Consent;
use Konventix\Compliance\Models\Policy;
use Konventix\Compliance\Models\PolicyVersion;
use Konventix\Identity\Models\User;
use Konventix\Registration\Events\RegistrationConsentGiven;
use Konventix\Registration\Models\EntryCondition;
use Konventix\Registration\Models\RegistrationType;

/**
 * Compliance's contribution to Registration's own `registration.consent`
 * slot (ADR-0066 §6.2, §7.1, D-CPL-10), and the listener that turns the
 * registrant's declaration into Consent rows.
 *
 * The slot is declared by the page that owns it and filled by the module
 * that owns the documents; Registration never learns that a policy exists
 * (ADR-0004, `tests/Unit/ArchitectureTest.php`). What this file pins is that
 * the context actually narrows: a text bound to one type appears on that
 * type's form and on no other, a text bound to an entry condition appears
 * from the condition the type carries, and an unbound text appears exactly
 * as it always did.
 *
 * Helper names prefixed `registrationConsent*`: Pest helpers are
 * process-global (`.ai/rules/tests.md`).
 */
function registrationConsentPolicy(string $slug, string $name): Policy
{
    $policy = Policy::factory()->create(['slug' => $slug, 'name' => $name]);
    PolicyVersion::factory()->for($policy)->published()->create([
        'version' => $slug.'-v1',
        'requires_consent' => true,
    ]);

    return $policy;
}

/**
 * Registration's own routes carry Compliance's `require-consent` gate, so a
 * visitor who has not yet agreed to the brand's required policies is
 * redirected before the form renders. That gate is not what this file is
 * about — it is `ConsentGateTest`'s — so the walking registrant is given the
 * agreement it asks for and the form is reached.
 *
 * The slot still renders its control afterwards: the order-level
 * declaration § 312j BGB asks for at the button is a different act from
 * agreeing to a published version (review 6 nit 11).
 */
function registrationConsentSettleGate(User $user): void
{
    foreach (PolicyVersion::withoutBrandScope()->where('requires_consent', true)->get() as $version) {
        Consent::factory()->create([
            'subject_id' => (string) $user->getKey(),
            'policy_version_id' => $version->getKey(),
        ]);
    }
}

it('shows an unbound text on every registration form, as it always did', function (): void {
    $this->withBrand();
    registrationConsentPolicy('terms', 'Terms of Participation');

    $type = RegistrationType::factory()->create();
    $user = User::factory()->create();
    registrationConsentSettleGate($user);

    $this->actingAs($user)
        ->get("/events/{$type->event_id}/register/{$type->id}")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('consentSlots', 1)
            ->where('consentSlots.0.component', 'compliance/RegistrationConsent')
            ->where('consentSlots.0.props.required', true)
            ->where('consentSlots.0.props.policies.0.slug', 'terms'));
});

it('shows a type-bound text on that type only', function (): void {
    $brand = $this->withBrand();
    $reduced = registrationConsentPolicy('reduced-fare', 'Reduced-fare terms');

    $student = RegistrationType::factory()->create(['name' => 'Student weekend']);
    $regular = RegistrationType::factory()->create([
        'event_id' => $student->event_id,
        'name' => 'Weekend',
    ]);

    app(BindPolicy::class)($reduced, PolicyBindingKind::RegistrationType, (string) $student->getKey(), $brand->getKey());

    $user = User::factory()->create();
    registrationConsentSettleGate($user);

    $this->actingAs($user)
        ->get("/events/{$student->event_id}/register/{$student->id}")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('consentSlots.0.props.policies.0.slug', 'reduced-fare'));

    $this->actingAs($user)
        ->get("/events/{$regular->event_id}/register/{$regular->id}")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            // Bound elsewhere, so it is not part of this purchase at all —
            // and with nothing else published, the control renders nothing.
            ->where('consentSlots.0.props.required', false)
            ->where('consentSlots.0.props.policies', []));
});

it('shows an entry-condition-bound text from the condition the type carries', function (): void {
    $brand = $this->withBrand();
    $policy = registrationConsentPolicy('student-declaration', 'Student declaration');

    $condition = EntryCondition::factory()->create(['key' => 'student', 'name' => 'Student ticket']);
    $type = RegistrationType::factory()->create();
    $type->entryConditions()->attach($condition->getKey(), ['position' => 0]);

    app(BindPolicy::class)($policy, PolicyBindingKind::EntryCondition, (string) $condition->getKey(), $brand->getKey());

    $user = User::factory()->create();
    registrationConsentSettleGate($user);

    $this->actingAs($user)
        ->get("/events/{$type->event_id}/register/{$type->id}")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('consentSlots.0.props.policies.0.slug', 'student-declaration'));

    // A type that carries no condition never matches that binding.
    $plain = RegistrationType::factory()->create(['event_id' => $type->event_id]);

    $this->actingAs($user)
        ->get("/events/{$plain->event_id}/register/{$plain->id}")
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('consentSlots.0.props.policies', []));
});

it('records one consent per matched version with the binding and its context', function (): void {
    // D-CPL-09/10: the declaration is Registration's event; which documents
    // it covers and why is Compliance's answer, resolved from the very
    // context the page rendered.
    $brand = $this->withBrand();
    $bound = registrationConsentPolicy('reduced-fare', 'Reduced-fare terms');
    $unbound = registrationConsentPolicy('terms', 'Terms of Participation');

    $type = RegistrationType::factory()->create();
    $binding = app(BindPolicy::class)($bound, PolicyBindingKind::RegistrationType, (string) $type->getKey(), $brand->getKey());

    $user = User::factory()->create();
    $registrationId = (string) Str::ulid();

    event(new RegistrationConsentGiven(
        brandId: $brand->getKey(),
        subjectId: (string) $user->getKey(),
        captureContext: 'registration',
        acceptedAt: now()->toIso8601String(),
        bindingContext: [
            'event_id' => $type->event_id,
            'registration_type_ids' => [(string) $type->getKey()],
            'entry_condition_ids' => [],
            'registration_id' => $registrationId,
        ],
    ));

    $consents = Consent::query()->where('subject_id', $user->getKey())->get();

    expect($consents)->toHaveCount(2)
        ->and($consents->pluck('capture_context')->unique()->all())->toBe(['registration']);

    $boundVersion = PolicyVersion::withoutBrandScope()->where('policy_id', $bound->getKey())->sole();
    $unboundVersion = PolicyVersion::withoutBrandScope()->where('policy_id', $unbound->getKey())->sole();

    $forBound = $consents->firstWhere('policy_version_id', $boundVersion->getKey());
    $forUnbound = $consents->firstWhere('policy_version_id', $unboundVersion->getKey());

    expect($forBound->policy_binding_id)->toBe((string) $binding->getKey())
        // Null for an unbound policy — the text applies everywhere, so no
        // binding made it applicable here (D-CPL-09).
        ->and($forUnbound->policy_binding_id)->toBeNull()
        // Evidence, not a read model: the scalars the resolution ran on.
        ->and($forBound->binding_context['registration_id'])->toBe($registrationId)
        ->and($forBound->binding_context['registration_type_ids'])->toBe([(string) $type->getKey()]);
});

it('records nothing for a text bound to something this registration is not', function (): void {
    $brand = $this->withBrand();
    $other = registrationConsentPolicy('other-terms', 'Other terms');

    $type = RegistrationType::factory()->create();
    $otherType = RegistrationType::factory()->create(['event_id' => $type->event_id]);

    app(BindPolicy::class)($other, PolicyBindingKind::RegistrationType, (string) $otherType->getKey(), $brand->getKey());

    $user = User::factory()->create();

    event(new RegistrationConsentGiven(
        brandId: $brand->getKey(),
        subjectId: (string) $user->getKey(),
        captureContext: 'registration',
        acceptedAt: now()->toIso8601String(),
        bindingContext: [
            'event_id' => $type->event_id,
            'registration_type_ids' => [(string) $type->getKey()],
        ],
    ));

    expect(Consent::query()->where('subject_id', $user->getKey())->count())->toBe(0);
});
