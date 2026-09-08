<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use Konventix\Compliance\Actions\PublishPolicyVersion;
use Konventix\Compliance\Enums\PolicyVersionState;
use Konventix\Compliance\Events\PolicyPublished;
use Konventix\Compliance\Exceptions\PolicyVersionNeedsEffectiveDateException;
use Konventix\Compliance\Models\Policy;
use Konventix\Compliance\Models\PolicyVersion;

it('publishes a policy version and emits PolicyPublished with the SPEC scalar payload', function (): void {
    $brand = $this->withBrand();
    $policy = Policy::factory()->terms()->create();
    $version = PolicyVersion::factory()->for($policy)->create([
        'version' => 'v1',
        'effective_at' => now()->subMinute(),
    ]);

    Event::fake([PolicyPublished::class]);

    $published = (new PublishPolicyVersion)($version);

    expect($published->state)->toBe(PolicyVersionState::Published)
        ->and($published->effective_at)->not->toBeNull()
        ->and($published->published_at)->not->toBeNull();

    Event::assertDispatched(
        PolicyPublished::class,
        fn (PolicyPublished $event): bool => $event->policyId === $policy->id
            && $event->versionId === $version->id
            && $event->brandId === $brand->id
            && $event->slug === 'terms'
            && $event->requiresConsent === true
            && is_string($event->effectiveAt),
    );
});

it('supersedes the previously published version so only one stays effective per policy', function (): void {
    $this->withBrand();
    $policy = Policy::factory()->create();
    $first = PolicyVersion::factory()->for($policy)->create([
        'version' => 'v1',
        'effective_at' => now()->subDay(),
    ]);
    $second = PolicyVersion::factory()->for($policy)->create([
        'version' => 'v2',
        'effective_at' => now()->subMinute(),
    ]);

    (new PublishPolicyVersion)($first);
    (new PublishPolicyVersion)($second);

    expect($first->refresh()->state)->toBe(PolicyVersionState::Superseded)
        ->and($second->refresh()->state)->toBe(PolicyVersionState::Published)
        ->and($policy->versions()->where('state', PolicyVersionState::Published)->count())->toBe(1);
});

it('does not disturb another policy\'s published version', function (): void {
    $this->withBrand();
    $termsVersion = PolicyVersion::factory()->for(Policy::factory()->terms())->published()->create();
    $privacyPolicy = Policy::factory()->privacy()->create();
    $privacyVersion = PolicyVersion::factory()->for($privacyPolicy)->create([
        'effective_at' => now()->subMinute(),
    ]);

    (new PublishPolicyVersion)($privacyVersion);

    expect($termsVersion->refresh()->state)->toBe(PolicyVersionState::Published)
        ->and($privacyVersion->refresh()->state)->toBe(PolicyVersionState::Published);
});

it('refuses to publish a version that carries no effective date', function (): void {
    $this->withBrand();

    $version = PolicyVersion::factory()->for(Policy::factory())->create(['effective_at' => null]);

    // The guard lives in the Action, so tinker is refused on the same grounds
    // as the console form. It used to fall back to `now()` in silence, which
    // is how a text ended up binding from the second it was published, with
    // nobody having said so (GitLab #203).
    expect(fn () => (new PublishPolicyVersion)($version))
        ->toThrow(PolicyVersionNeedsEffectiveDateException::class);

    expect($version->refresh()->state)->toBe(PolicyVersionState::Draft);
});
