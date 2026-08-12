<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use Konventix\Compliance\Actions\PublishPolicyVersion;
use Konventix\Compliance\Enums\PolicyVersionState;
use Konventix\Compliance\Events\PolicyPublished;
use Konventix\Compliance\Models\Policy;
use Konventix\Compliance\Models\PolicyVersion;

it('publishes a policy version and emits PolicyPublished with the SPEC scalar payload', function (): void {
    $brand = $this->withBrand();
    $policy = Policy::factory()->terms()->create();
    $version = PolicyVersion::factory()->for($policy)->create(['version' => 'v1']);

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
    $first = PolicyVersion::factory()->for($policy)->create(['version' => 'v1']);
    $second = PolicyVersion::factory()->for($policy)->create(['version' => 'v2']);

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
    $privacyVersion = PolicyVersion::factory()->for($privacyPolicy)->create();

    (new PublishPolicyVersion)($privacyVersion);

    expect($termsVersion->refresh()->state)->toBe(PolicyVersionState::Published)
        ->and($privacyVersion->refresh()->state)->toBe(PolicyVersionState::Published);
});
