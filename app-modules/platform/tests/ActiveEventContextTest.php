<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Konventix\Admission\Events\ParticipantAdmissionReplaced;
use Konventix\Admission\Events\ParticipantHubAccessEnded;
use Konventix\Admission\Events\ParticipantPresenceChanged;
use Konventix\Compliance\Enums\DeletionMode;
use Konventix\Compliance\Gdpr\SubjectRef;
use Konventix\Identity\Models\User;
use Konventix\Platform\Gdpr\HubAccessGdprHandler;
use Konventix\Platform\Hub\ActiveEventContext;
use Konventix\Platform\Models\Brand;
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

// A separate test only for readability — since the memo is keyed by viewer
// (below), the guest read above and this one could safely share a test.
it('is null for a signed-in user nobody admitted, and caches that answer', function (): void {
    $brand = $this->withBrand();
    $user = User::factory()->create();
    $this->actingAs($user);

    expect(app(ActiveEventContext::class)->current())->toBeNull()
        ->and(Cache::has(ActiveEventContext::key($brand->id, $user->id)))->toBeTrue();
});

// The memo is keyed by the viewer it was resolved for. A container that
// outlives one viewer — the test runner, which reuses scoped instances
// across requests, or any code switching the authenticated user mid-request
// — must re-resolve rather than hand on the previous viewer's events.
it('re-resolves when the viewer changes instead of handing on the previous memo', function (): void {
    $brand = $this->withBrand();
    $first = User::factory()->create();
    $second = User::factory()->create();
    event(hubAdmitted($brand->id, $first->id, $firstEvent = (string) Str::ulid()));
    event(hubAdmitted($brand->id, $second->id, $secondEvent = (string) Str::ulid()));

    $context = app(ActiveEventContext::class);

    $this->actingAs($first);
    expect($context->current()?->eventId)->toBe($firstEvent);

    $this->actingAs($second);
    expect($context->current()?->eventId)->toBe($secondEvent);
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

// Fix round 1: a holder with two admissions who loses one keeps access on
// the survivor, and the mirror has to follow — `ActiveEventData::admissionId`
// is what Resources' "Mein Sitzplatz" link is built from, so a stale id
// sends the participant to a revoked admission.
it('re-points admission_id on a replacement and forgets the cache', function (): void {
    $brand = $this->withBrand();
    $user = User::factory()->create();
    event(hubAdmitted($brand->id, $user->id, $eventId = (string) Str::ulid(), admissionId: $revoked = (string) Str::ulid()));
    $this->actingAs($user);
    expect(app(ActiveEventContext::class)->current()?->admissionId)->toBe($revoked)
        ->and(Cache::has(ActiveEventContext::key($brand->id, $user->id)))->toBeTrue();

    event(new ParticipantAdmissionReplaced($user->id, $eventId, $brand->id, $survivor = (string) Str::ulid(), now()->toIso8601String()));

    expect(HubAccess::query()->firstOrFail()->admission_id)->toBe($survivor)
        ->and(Cache::has(ActiveEventContext::key($brand->id, $user->id)))->toBeFalse();
});

// A replacement for somebody this table never admitted changes nothing,
// like a presence change — the admission event is what carries a row into
// being.
it('ignores a replacement for a user it never admitted', function (): void {
    $brand = $this->withBrand();
    $user = User::factory()->create();

    event(new ParticipantAdmissionReplaced($user->id, (string) Str::ulid(), $brand->id, (string) Str::ulid(), now()->toIso8601String()));

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

// The rows are only half of it: `hub-access:{brand}:{user}` carries the
// event's name, the admission id and the instant they were admitted for ten
// minutes, so an erasure that leaves the key behind keeps serving all three.
it('forgets the cached answer on erasure, on every brand the subject was admitted on', function (): void {
    $brand = $this->withBrand();
    $second = Brand::factory()->create();
    $user = User::factory()->create();

    foreach ([$brand, $second] as $on) {
        event(hubAdmitted($on->id, $user->id, (string) Str::ulid()));

        // The brand context is scoped, so it has to be set *after* the
        // forget, not before — otherwise the context resolves for a guest
        // on no brand and caches nothing.
        $this->app->forgetScopedInstances();
        $this->withBrand($on);
        $this->actingAs($user);

        expect(app(ActiveEventContext::class)->current())->not->toBeNull();
    }

    expect(Cache::has(ActiveEventContext::key($brand->id, $user->id)))->toBeTrue()
        ->and(Cache::has(ActiveEventContext::key($second->id, $user->id)))->toBeTrue();

    (new HubAccessGdprHandler)->delete(SubjectRef::forUser($user->id), DeletionMode::Erase);

    expect(Cache::has(ActiveEventContext::key($brand->id, $user->id)))->toBeFalse()
        ->and(Cache::has(ActiveEventContext::key($second->id, $user->id)))->toBeFalse();
});
