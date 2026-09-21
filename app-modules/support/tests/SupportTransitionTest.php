<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Konventix\Support\Actions\TransitionSupportRequest;
use Konventix\Support\Enums\SupportRequestState;
use Konventix\Support\Events\SupportRequestStateChanged;
use Konventix\Support\Exceptions\InvalidSupportTransition;
use Konventix\Support\Models\SupportRequest;

/**
 * The transition table of design §4.3 and the three timestamps that hang
 * off it. `TransitionSupportRequest` is the only way a state ever changes,
 * so this file is where the table is actually enforced.
 */
it('walks the whole happy path and stamps each timestamp once', function (): void {
    $request = SupportRequest::factory()->create();
    $action = app(TransitionSupportRequest::class);

    $action->handle($request, SupportRequestState::Seen);
    $seenAt = $request->refresh()->seen_at;

    expect($seenAt)->not->toBeNull()
        ->and($request->closed_at)->toBeNull();

    $action->handle($request, SupportRequestState::InProgress);
    $action->handle($request, SupportRequestState::Resolved);
    $request->refresh();

    expect($request->state)->toBe(SupportRequestState::Resolved)
        ->and($request->resolved_at)->not->toBeNull()
        ->and($request->closed_at)->not->toBeNull()
        // Stamped once: the report was seen, and being worked on again
        // later does not un-see it.
        ->and($request->seen_at?->toIso8601String())->toBe($seenAt?->toIso8601String());
});

it('refuses a move the table does not allow', function (): void {
    $request = SupportRequest::factory()->create();

    app(TransitionSupportRequest::class)->handle($request, SupportRequestState::Resolved);
})->throws(InvalidSupportTransition::class);

it('refuses to walk backwards into received', function (): void {
    $request = SupportRequest::factory()->inState(SupportRequestState::InProgress)->create();

    app(TransitionSupportRequest::class)->handle($request, SupportRequestState::Received);
})->throws(InvalidSupportTransition::class);

/**
 * The console's first-open path runs on every page load, so the second one
 * must neither throw nor emit a second event.
 */
it('treats a move into the current state as a no-op', function (): void {
    Event::fake([SupportRequestStateChanged::class]);

    $request = SupportRequest::factory()->inState(SupportRequestState::Seen)->create();

    app(TransitionSupportRequest::class)->handle($request, SupportRequestState::Seen);

    Event::assertNotDispatched(SupportRequestStateChanged::class);
});

/**
 * Reopening is the one way back, and it must clear the retention clock —
 * otherwise a reopened report is purged out from under the person still
 * waiting on it (D-SUP-11).
 */
it('clears the retention clock when a closed report is reopened', function (): void {
    $request = SupportRequest::factory()->inState(SupportRequestState::WontFix)->create();

    expect($request->closed_at)->not->toBeNull();

    app(TransitionSupportRequest::class)->handle($request, SupportRequestState::InProgress);

    expect($request->refresh()->closed_at)->toBeNull()
        ->and($request->resolved_at)->toBeNull()
        ->and($request->seen_at)->not->toBeNull();
});

it('announces every move with scalars only and names the actor', function (): void {
    Event::fake([SupportRequestStateChanged::class]);

    $brand = $this->withBrand();
    $actor = (string) Str::ulid();
    $request = SupportRequest::factory()->filedOnBrand($brand->getKey())->create();

    app(TransitionSupportRequest::class)->handle($request, SupportRequestState::Seen, $actor);

    Event::assertDispatched(SupportRequestStateChanged::class, function (SupportRequestStateChanged $event) use ($request, $brand, $actor): bool {
        return $event->requestId === $request->getKey()
            && $event->brandId === $brand->getKey()
            && $event->from === 'received'
            && $event->to === 'seen'
            && $event->actorUserId === $actor;
    });
});

it('writes an audit entry for every move', function (): void {
    $request = SupportRequest::factory()->create();
    $actor = (string) Str::ulid();

    $entry = (new SupportRequestStateChanged(
        requestId: $request->getKey(),
        brandId: null,
        from: 'seen',
        to: 'resolved',
        actorUserId: $actor,
    ))->auditEntry();

    expect($entry->action)->toBe('support.request.state-changed')
        ->and($entry->subjectId)->toBe($request->getKey())
        ->and($entry->actorUserId)->toBe($actor)
        ->and($entry->context)->toBe(['from' => 'seen', 'to' => 'resolved']);
});
