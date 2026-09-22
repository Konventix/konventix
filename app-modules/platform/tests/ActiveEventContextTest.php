<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Konventix\Admission\Events\ParticipantHubAccessEnded;
use Konventix\Admission\Events\ParticipantPresenceChanged;
use Konventix\Compliance\Enums\DeletionMode;
use Konventix\Compliance\Gdpr\SubjectRef;
use Konventix\Identity\Models\User;
use Konventix\Platform\Gdpr\HubAccessGdprHandler;
use Konventix\Platform\Hub\ActiveEventContext;
use Konventix\Platform\Models\HubAccess;

it('resolves the admitted event for the signed-in user from one cached read', function (): void {
    $brand = $this->withBrand();
    $user = User::factory()->create();
    event(hubAdmitted($brand->id, $user->id, $eventId = (string) Str::ulid()));

    $this->actingAs($user);
    $context = app(ActiveEventContext::class);

    expect($context->current()?->eventId)->toBe($eventId)
        ->and($context->current()?->onSite)->toBeTrue()
        ->and(Cache::has("hub-access:{$brand->id}:{$user->id}"))->toBeTrue();
});

it('is null for a guest', function (): void {
    $this->withBrand();

    expect(app(ActiveEventContext::class)->current())->toBeNull();
});

// A separate test on purpose: the context is scoped and memoises its
// answer, so a guest read in the same request would hand the signed-in
// read the memo and this branch would never run.
it('is null for a signed-in user nobody admitted, and caches that answer', function (): void {
    $brand = $this->withBrand();
    $user = User::factory()->create();
    $this->actingAs($user);

    expect(app(ActiveEventContext::class)->current())->toBeNull()
        ->and(Cache::has(ActiveEventContext::key($brand->id, $user->id)))->toBeTrue();
});

it('prefers the most recent admission and lists all', function (): void {
    $brand = $this->withBrand();
    $user = User::factory()->create();
    event(hubAdmitted($brand->id, $user->id, $older = (string) Str::ulid(), '2026-10-16T18:00:00Z'));
    event(hubAdmitted($brand->id, $user->id, $newer = (string) Str::ulid(), '2026-10-16T19:00:00Z'));

    $this->actingAs($user);
    $context = app(ActiveEventContext::class);

    expect($context->current()?->eventId)->toBe($newer)->and($context->all())->toHaveCount(2);
});

it('drops the row and the cache when access ends, and flips on_site on presence changes', function (): void {
    $brand = $this->withBrand();
    $user = User::factory()->create();
    event(hubAdmitted($brand->id, $user->id, $eventId = (string) Str::ulid()));
    $this->actingAs($user);
    app(ActiveEventContext::class)->current();

    event(new ParticipantPresenceChanged($user->id, $eventId, $brand->id, 'out', now()->toIso8601String()));
    expect(Cache::has("hub-access:{$brand->id}:{$user->id}"))->toBeFalse()
        ->and(HubAccess::query()->firstOrFail()->on_site)->toBeFalse();

    event(new ParticipantHubAccessEnded($user->id, $eventId, $brand->id, 'concluded', now()->toIso8601String()));
    expect(HubAccess::query()->count())->toBe(0);
});

it('exports and erases hub access rows for a subject', function (): void {
    $brand = $this->withBrand();
    $user = User::factory()->create();
    event(hubAdmitted($brand->id, $user->id, (string) Str::ulid()));

    $handler = new HubAccessGdprHandler;
    expect($handler->exportFor(SubjectRef::forUser($user->id))['hub_access'])->toHaveCount(1);

    $handler->delete(SubjectRef::forUser($user->id), DeletionMode::Anonymize);
    expect(HubAccess::query()->count())->toBe(0);
});
