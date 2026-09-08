<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Konventix\Compliance\Actions\OpenSubjectRequest;
use Konventix\Compliance\Enums\DataSubjectRequestState;
use Konventix\Compliance\Enums\DataSubjectRequestType;
use Konventix\Compliance\Enums\DeletionMode;
use Konventix\Compliance\Events\DataExportRequested;
use Konventix\Compliance\Events\UserDeletionRequested;
use Konventix\Compliance\Gdpr\GdprHandlerRegistry;
use Konventix\Compliance\Gdpr\SubjectRef;

it('opens an export request, sets the statutory 30-day deadline, and emits DataExportRequested', function (): void {
    $brand = $this->withBrand();
    $subjectId = (string) Str::ulid();

    Event::fake([DataExportRequested::class, UserDeletionRequested::class]);

    $request = (new OpenSubjectRequest(app(GdprHandlerRegistry::class)))(
        SubjectRef::forUser($subjectId),
        DataSubjectRequestType::Export,
        null,
        $brand->getKey(),
    );

    expect($request->type)->toBe(DataSubjectRequestType::Export)
        ->and($request->state)->toBe(DataSubjectRequestState::Received)
        // Art. 12(3) gives a month, and a month is a day rather than a
        // moment: the deadline is the end of the thirtieth day, so that the
        // console, the public page and the escalation mail cannot name three
        // different dates for it (branch review finding 16).
        ->and($request->created_at->toDateString())
        ->toBe($request->deadline_at->copy()->subDays(30)->toDateString())
        ->and($request->deadline_at->format('H:i:s'))->toBe('23:59:59');

    Event::assertDispatched(
        DataExportRequested::class,
        fn (DataExportRequested $event): bool => $event->requestId === $request->id
            && $event->subjectId === $subjectId
            && $event->brandId === $brand->id
            && is_string($event->requestedAt),
    );
});

it('opens a deletion request with a mode and emits UserDeletionRequested', function (): void {
    $this->withBrand();
    $subjectId = (string) Str::ulid();

    Event::fake([DataExportRequested::class, UserDeletionRequested::class]);

    $request = (new OpenSubjectRequest(app(GdprHandlerRegistry::class)))(
        SubjectRef::forUser($subjectId),
        DataSubjectRequestType::Deletion,
        DeletionMode::Erase,
    );

    expect($request->mode)->toBe(DeletionMode::Erase);

    Event::assertDispatched(
        UserDeletionRequested::class,
        fn (UserDeletionRequested $event): bool => $event->requestId === $request->id
            && $event->subjectId === $subjectId
            && $event->mode === 'erase',
    );
});

it('refuses to open a deletion request with no mode', function (): void {
    $this->withBrand();

    (new OpenSubjectRequest(app(GdprHandlerRegistry::class)))(
        SubjectRef::forUser((string) Str::ulid()),
        DataSubjectRequestType::Deletion,
    );
})->throws(InvalidArgumentException::class);

it('opens a rectification request without dispatching an event, since SPEC defines none yet', function (): void {
    $this->withBrand();

    Event::fake([DataExportRequested::class, UserDeletionRequested::class]);

    $request = (new OpenSubjectRequest(app(GdprHandlerRegistry::class)))(
        SubjectRef::forUser((string) Str::ulid()),
        DataSubjectRequestType::Rectification,
    );

    expect($request->type)->toBe(DataSubjectRequestType::Rectification);

    Event::assertNotDispatched(DataExportRequested::class);
    Event::assertNotDispatched(UserDeletionRequested::class);
});
