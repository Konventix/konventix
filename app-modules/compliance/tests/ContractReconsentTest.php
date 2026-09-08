<?php

declare(strict_types=1);

use Konventix\Compliance\Enums\LawfulBasis;
use Konventix\Compliance\Enums\PolicyVersionState;
use Konventix\Compliance\Models\Consent;
use Konventix\Compliance\Models\Policy;
use Konventix\Compliance\Models\PolicyVersion;
use Konventix\Compliance\Support\OutstandingConsents;
use Konventix\Identity\Models\User;

/**
 * The narrower question the login and checkout gates ask (GitLab #205):
 * not "does this subject owe any agreement" but "does this subject owe one
 * to a text that is the contract itself" (`LawfulBasis::Contract`).
 *
 * A contract text is what the sale rests on, so a new version of one has to
 * be put in front of the person again — exactly once, at the next login or
 * the next checkout, whichever comes first.
 */
it('reports an unagreed published contract version as outstanding', function (): void {
    $this->withBrand();
    $user = User::factory()->create();

    PolicyVersion::factory()->for(Policy::factory()->terms())->published()->create([
        'requires_consent' => true,
        'lawful_basis' => LawfulBasis::Contract,
    ]);

    expect(app(OutstandingConsents::class)->subjectHasOutstandingContract($user->getKey()))->toBeTrue();
});

it('leaves a consent-based required text to the ordinary gate', function (): void {
    $this->withBrand();
    $user = User::factory()->create();

    PolicyVersion::factory()->for(Policy::factory()->photoConsent())->published()->create([
        'requires_consent' => true,
        'lawful_basis' => LawfulBasis::Consent,
    ]);

    $outstanding = app(OutstandingConsents::class);

    expect($outstanding->subjectHasOutstanding($user->getKey()))->toBeTrue()
        ->and($outstanding->subjectHasOutstandingContract($user->getKey()))->toBeFalse();
});

it('ignores a contract text nobody must agree to, and one whose effective date has not arrived', function (): void {
    $this->withBrand();
    $user = User::factory()->create();

    PolicyVersion::factory()->for(Policy::factory()->create(['slug' => 'house-rules']))->published()->create([
        'requires_consent' => false,
        'lawful_basis' => LawfulBasis::Contract,
    ]);

    PolicyVersion::factory()->for(Policy::factory()->terms())->published()->create([
        'requires_consent' => true,
        'lawful_basis' => LawfulBasis::Contract,
        'effective_at' => now()->addWeek(),
    ]);

    expect(app(OutstandingConsents::class)->subjectHasOutstandingContract($user->getKey()))->toBeFalse();
});

it('asks once per version: agreeing clears it, and a newly published version raises it again', function (): void {
    $this->withBrand();
    $user = User::factory()->create();
    $policy = Policy::factory()->terms()->create();

    $first = PolicyVersion::factory()->for($policy)->published()->create([
        'version' => 'terms-v1',
        'requires_consent' => true,
        'lawful_basis' => LawfulBasis::Contract,
    ]);

    $outstanding = app(OutstandingConsents::class);

    expect($outstanding->subjectHasOutstandingContract($user->getKey()))->toBeTrue();

    Consent::factory()->for($first, 'policyVersion')->create(['subject_id' => $user->getKey()]);

    expect($outstanding->subjectHasOutstandingContract($user->getKey()))->toBeFalse();

    // A new version supersedes the old one; consent is recorded per version
    // id, so the agreement on `terms-v1` says nothing about `terms-v2`.
    $first->forceFill(['state' => PolicyVersionState::Superseded])->save();

    $second = PolicyVersion::factory()->for($policy)->published()->create([
        'version' => 'terms-v2',
        'requires_consent' => true,
        'lawful_basis' => LawfulBasis::Contract,
    ]);

    expect($outstanding->subjectHasOutstandingContract($user->getKey()))->toBeTrue();

    Consent::factory()->for($second, 'policyVersion')->create(['subject_id' => $user->getKey()]);

    expect($outstanding->subjectHasOutstandingContract($user->getKey()))->toBeFalse();
});

it('raises a withdrawn agreement to a contract text again', function (): void {
    $this->withBrand();
    $user = User::factory()->create();

    $version = PolicyVersion::factory()->for(Policy::factory()->terms())->published()->create([
        'requires_consent' => true,
        'lawful_basis' => LawfulBasis::Contract,
    ]);

    Consent::factory()->for($version, 'policyVersion')->create([
        'subject_id' => $user->getKey(),
        'withdrawn_at' => now(),
    ]);

    expect(app(OutstandingConsents::class)->subjectHasOutstandingContract($user->getKey()))->toBeTrue();
});
