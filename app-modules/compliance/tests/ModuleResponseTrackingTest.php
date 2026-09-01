<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Konventix\Compliance\Actions\OpenSubjectRequest;
use Konventix\Compliance\Actions\RecordModuleResponse;
use Konventix\Compliance\Actions\RedispatchSubjectRequest;
use Konventix\Compliance\Enums\DataSubjectRequestType;
use Konventix\Compliance\Enums\DeletionMode;
use Konventix\Compliance\Enums\ModuleResponseState;
use Konventix\Compliance\Events\DataExportRequested;
use Konventix\Compliance\Gdpr\GdprHandler;
use Konventix\Compliance\Gdpr\GdprHandlerRegistry;
use Konventix\Compliance\Gdpr\SubjectRef;

/**
 * A minimal fake handler, standing in for a real module's Gdpr/ handler —
 * only `module()` matters for these tests.
 */
final class FakeStaffingGdprHandler implements GdprHandler
{
    public function module(): string
    {
        return 'staffing';
    }

    public function exportFor(SubjectRef $subject): array
    {
        return ['shifts' => []];
    }

    public function delete(SubjectRef $subject, DeletionMode $mode): void {}
}

/*
 * The fan-out now runs for real when a request opens (sync queue in tests),
 * and an export writes an archive — onto a fake disk here, never the
 * repository's own storage directory.
 */
beforeEach(function (): void {
    Storage::fake('exports');
});

it('seeds a pending response row for every registered module when a request opens', function (): void {
    $this->withBrand();
    $registry = app(GdprHandlerRegistry::class);
    $registry->register(new FakeStaffingGdprHandler);

    // The fan-out job would run inline under `QUEUE_CONNECTION=sync` and
    // complete these rows immediately; what is under test here is the seeding
    // OpenSubjectRequest does before any handler is called.
    Queue::fake();

    $request = (new OpenSubjectRequest($registry))((string) Str::ulid(), DataSubjectRequestType::Export);

    expect($request->responses()->where('module', 'staffing')->count())->toBe(1);

    $response = $request->responses()->where('module', 'staffing')->first();

    expect($response->module)->toBe('staffing')
        ->and($response->state)->toBe(ModuleResponseState::Pending)
        ->and($response->responded_at)->toBeNull();
});

it('records a module response idempotently — recording twice does not duplicate the row', function (): void {
    $this->withBrand();
    $registry = app(GdprHandlerRegistry::class);
    $registry->register(new FakeStaffingGdprHandler);
    $request = (new OpenSubjectRequest($registry))((string) Str::ulid(), DataSubjectRequestType::Export);

    (new RecordModuleResponse)($request, 'staffing', ModuleResponseState::Completed);
    (new RecordModuleResponse)($request, 'staffing', ModuleResponseState::Completed);

    expect($request->responses()->where('module', 'staffing')->count())->toBe(1);

    $response = $request->responses()->where('module', 'staffing')->first();

    expect($response->state)->toBe(ModuleResponseState::Completed)
        ->and($response->responded_at)->not->toBeNull();
});

it('re-dispatches the export fan-out event without duplicating or losing response rows', function (): void {
    $this->withBrand();
    $registry = app(GdprHandlerRegistry::class);
    $registry->register(new FakeStaffingGdprHandler);
    $request = (new OpenSubjectRequest($registry))((string) Str::ulid(), DataSubjectRequestType::Export);
    (new RecordModuleResponse)($request, 'staffing', ModuleResponseState::Failed);

    Event::fake([DataExportRequested::class, UserDeletionRequested::class]);

    (new RedispatchSubjectRequest)($request);
    (new RedispatchSubjectRequest)($request);

    Event::assertDispatchedTimes(DataExportRequested::class, 2);

    expect($request->responses()->where('module', 'staffing')->count())->toBe(1)
        ->and($request->responses()->where('module', 'staffing')->first()->state)->toBe(ModuleResponseState::Failed);
});

it('lets a module overwrite its failed response after re-dispatch, still without duplicating the row', function (): void {
    $this->withBrand();
    $registry = app(GdprHandlerRegistry::class);
    $registry->register(new FakeStaffingGdprHandler);
    $request = (new OpenSubjectRequest($registry))((string) Str::ulid(), DataSubjectRequestType::Export);
    (new RecordModuleResponse)($request, 'staffing', ModuleResponseState::Failed);

    (new RedispatchSubjectRequest)($request);
    (new RecordModuleResponse)($request, 'staffing', ModuleResponseState::Completed);

    expect($request->responses()->where('module', 'staffing')->count())->toBe(1)
        ->and($request->responses()->where('module', 'staffing')->first()->state)->toBe(ModuleResponseState::Completed);
});
