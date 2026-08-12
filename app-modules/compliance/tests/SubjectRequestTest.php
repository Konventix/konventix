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

it('opens an export request, sets the statutory 30-day deadline, and emits DataExportRequested', function (): void {
    $brand = $this->withBrand();
    $subjectId = (string) Str::ulid();

    Event::fake([DataExportRequested::class, UserDeletionRequested::class]);

    $request = (new OpenSubjectRequest(app(GdprHandlerRegistry::class)))(
        $subjectId,
        DataSubjectRequestType::Export,
    );

    expect($request->type)->toBe(DataSubjectRequestType::Export)
        ->and($request->state)->toBe(DataSubjectRequestState::Received)
        ->and($request->created_at->diffInDays($request->deadline_at))->toBe(30.0);

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
        $subjectId,
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
        (string) Str::ulid(),
        DataSubjectRequestType::Deletion,
    );
})->throws(InvalidArgumentException::class);

it('opens a rectification request without dispatching an event, since SPEC defines none yet', function (): void {
    $this->withBrand();

    Event::fake([DataExportRequested::class, UserDeletionRequested::class]);

    $request = (new OpenSubjectRequest(app(GdprHandlerRegistry::class)))(
        (string) Str::ulid(),
        DataSubjectRequestType::Rectification,
    );

    expect($request->type)->toBe(DataSubjectRequestType::Rectification);

    Event::assertNotDispatched(DataExportRequested::class);
    Event::assertNotDispatched(UserDeletionRequested::class);
});
