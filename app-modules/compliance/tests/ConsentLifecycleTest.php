<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Konventix\Compliance\Actions\GrantConsent;
use Konventix\Compliance\Actions\WithdrawConsent;
use Konventix\Compliance\Events\ConsentGranted;
use Konventix\Compliance\Events\ConsentWithdrawn;
use Konventix\Compliance\Models\Policy;
use Konventix\Compliance\Models\PolicyVersion;

it('grants consent and emits ConsentGranted with the SPEC scalar payload', function (): void {
    $brand = $this->withBrand();
    $policy = Policy::factory()->terms()->create();
    $version = PolicyVersion::factory()->for($policy)->published()->create();
    $subjectId = (string) Str::ulid();

    Event::fake([ConsentGranted::class, ConsentWithdrawn::class]);

    $consent = (new GrantConsent)($subjectId, $version, 'registration_form');

    expect($consent->subject_id)->toBe($subjectId)
        ->and($consent->policy_version_id)->toBe($version->id)
        ->and($consent->brand_id)->toBe($brand->id)
        ->and($consent->capture_context)->toBe('registration_form')
        ->and($consent->isWithdrawn())->toBeFalse();

    Event::assertDispatched(
        ConsentGranted::class,
        fn (ConsentGranted $event): bool => $event->consentId === $consent->id
            && $event->subjectId === $subjectId
            && $event->policyId === $policy->id
            && $event->versionId === $version->id
            && $event->brandId === $brand->id
            && is_string($event->grantedAt),
    );
});

it('withdraws a granted consent and emits ConsentWithdrawn', function (): void {
    $this->withBrand();
    $policy = Policy::factory()->terms()->create();
    $version = PolicyVersion::factory()->for($policy)->published()->create();
    $consent = (new GrantConsent)((string) Str::ulid(), $version, 'registration_form');

    Event::fake([ConsentGranted::class, ConsentWithdrawn::class]);

    $withdrawn = (new WithdrawConsent)($consent);

    expect($withdrawn->isWithdrawn())->toBeTrue()
        ->and($withdrawn->withdrawn_at)->not->toBeNull();

    Event::assertDispatched(
        ConsentWithdrawn::class,
        fn (ConsentWithdrawn $event): bool => $event->consentId === $consent->id
            && $event->subjectId === $consent->subject_id
            && $event->policyId === $policy->id
            && is_string($event->withdrawnAt),
    );
});

it('tolerates a subject_id that is not backed by any users row', function (): void {
    $this->withBrand();
    $version = PolicyVersion::factory()->for(Policy::factory()->photoConsent())->published()->create();

    $consent = (new GrantConsent)((string) Str::ulid(), $version, 'guest_capture');

    expect($consent->subject_id)->toBeString()->and(mb_strlen($consent->subject_id))->toBe(26);
});
