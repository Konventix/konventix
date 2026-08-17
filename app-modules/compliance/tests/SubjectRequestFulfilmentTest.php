<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Konventix\Compliance\Actions\OpenSubjectRequest;
use Konventix\Compliance\Actions\RedispatchSubjectRequest;
use Konventix\Compliance\Enums\DataSubjectRequestState;
use Konventix\Compliance\Enums\DataSubjectRequestType;
use Konventix\Compliance\Enums\DeletionMode;
use Konventix\Compliance\Enums\ModuleResponseState;
use Konventix\Compliance\Events\DataExportCompleted;
use Konventix\Compliance\Events\UserDeletionCompleted;
use Konventix\Compliance\Gdpr\GdprHandler;
use Konventix\Compliance\Gdpr\GdprHandlerRegistry;
use Konventix\Compliance\Models\DataSubjectRequest;
use Konventix\Compliance\Models\DataSubjectRequestResponse;

/**
 * The fan-out actually carrying out a request: every registered handler is
 * called through the contract, its answer recorded per module, and — only
 * when all of them succeeded — the archive written and the request closed.
 *
 * `QUEUE_CONNECTION=sync` in phpunit.xml runs the jobs inline, so opening a
 * request exercises the whole path.
 */
function complianceFakeHandler(string $module, array $export = [], bool $throws = false): GdprHandler
{
    return new class($module, $export, $throws) implements GdprHandler
    {
        /** @var list<array{subject: string, mode: string}> */
        public array $deletions = [];

        public function __construct(
            private readonly string $module,
            private readonly array $export,
            private readonly bool $throws,
        ) {}

        public function module(): string
        {
            return $this->module;
        }

        public function exportFor(string $subjectId, string $brandId): array
        {
            if ($this->throws) {
                throw new RuntimeException('handler exploded');
            }

            return $this->export;
        }

        public function delete(string $subjectId, string $brandId, DeletionMode $mode): void
        {
            if ($this->throws) {
                throw new RuntimeException('handler exploded');
            }

            $this->deletions[] = ['subject' => $subjectId, 'mode' => $mode->value];
        }
    };
}

it('assembles every handler contribution into one archive and closes the request', function (): void {
    $brand = $this->withBrand();
    Storage::fake('exports');

    $registry = app(GdprHandlerRegistry::class);
    $registry->register(complianceFakeHandler('registration', ['registrations' => [['id' => 'r1']]]));
    $registry->register(complianceFakeHandler('commerce', ['orders' => []]));

    $subjectId = (string) Str::ulid();

    Event::fake([DataExportCompleted::class]);

    $request = app(OpenSubjectRequest::class)($subjectId, DataSubjectRequestType::Export);
    $request->refresh();

    expect($request->state)->toBe(DataSubjectRequestState::Fulfilled)
        ->and($request->archive_disk)->toBe('exports')
        ->and($request->closed_at)->not->toBeNull();

    Storage::disk('exports')->assertExists((string) $request->archive_path);

    $archive = json_decode(Storage::disk('exports')->get((string) $request->archive_path), true);

    expect($archive['subject_id'])->toBe($subjectId)
        ->and($archive['modules'])->toHaveKeys(['registration', 'commerce'])
        ->and($archive['modules']['registration']['registrations'][0]['id'])->toBe('r1');

    // Named rather than counted: every module that ships a handler answers
    // too, and a total would break each time another one lands.
    $states = DataSubjectRequestResponse::query()
        ->where('data_subject_request_id', $request->id)
        ->pluck('state', 'module');

    expect($states['registration'])->toBe(ModuleResponseState::Completed)
        ->and($states['commerce'])->toBe(ModuleResponseState::Completed);

    Event::assertDispatched(
        DataExportCompleted::class,
        fn (DataExportCompleted $event): bool => $event->requestId === $request->id
            && $event->brandId === $brand->id,
    );
});

it('leaves a request open when one module fails, and writes no partial archive', function (): void {
    $this->withBrand();
    Storage::fake('exports');

    $registry = app(GdprHandlerRegistry::class);
    $registry->register(complianceFakeHandler('registration', ['registrations' => []]));
    $registry->register(complianceFakeHandler('commerce', throws: true));

    $request = app(OpenSubjectRequest::class)((string) Str::ulid(), DataSubjectRequestType::Export);
    $request->refresh();

    expect($request->state)->toBe(DataSubjectRequestState::InProgress)
        ->and($request->archive_path)->toBeNull();

    expect(Storage::disk('exports')->allFiles())->toBeEmpty();

    $states = DataSubjectRequestResponse::query()
        ->where('data_subject_request_id', $request->id)
        ->pluck('state', 'module');

    expect($states['registration'])->toBe(ModuleResponseState::Completed)
        ->and($states['commerce'])->toBe(ModuleResponseState::Failed);
});

it('runs every handler for a deletion and records the mode', function (): void {
    $this->withBrand();

    $handler = complianceFakeHandler('registration');
    app(GdprHandlerRegistry::class)->register($handler);

    $subjectId = (string) Str::ulid();

    Event::fake([UserDeletionCompleted::class]);

    $request = app(OpenSubjectRequest::class)(
        $subjectId,
        DataSubjectRequestType::Deletion,
        DeletionMode::Anonymize,
    );

    expect($handler->deletions)->toBe([['subject' => $subjectId, 'mode' => 'anonymize']])
        ->and($request->refresh()->state)->toBe(DataSubjectRequestState::Fulfilled);

    Event::assertDispatched(UserDeletionCompleted::class);
});

it('re-dispatching a failed fan-out completes it', function (): void {
    $this->withBrand();
    Storage::fake('exports');

    $registry = app(GdprHandlerRegistry::class);
    $registry->register(complianceFakeHandler('commerce', throws: true));

    $request = app(OpenSubjectRequest::class)((string) Str::ulid(), DataSubjectRequestType::Export);

    expect($request->refresh()->state)->toBe(DataSubjectRequestState::InProgress);

    // The module's handler now works — the same registry key, replaced the
    // way a fixed deployment would.
    $registry->register(complianceFakeHandler('commerce', ['orders' => []]));

    app(RedispatchSubjectRequest::class)($request);

    expect($request->refresh()->state)->toBe(DataSubjectRequestState::Fulfilled)
        ->and($request->archive_path)->not->toBeNull();
});

it('does not fan out a refused request', function (): void {
    $this->withBrand();
    Storage::fake('exports');

    $handler = complianceFakeHandler('registration');
    app(GdprHandlerRegistry::class)->register($handler);

    $request = DataSubjectRequest::factory()->create([
        'type' => DataSubjectRequestType::Deletion,
        'state' => DataSubjectRequestState::Refused,
        'mode' => DeletionMode::Anonymize,
    ]);

    app(RedispatchSubjectRequest::class)($request);

    expect($handler->deletions)->toBeEmpty()
        ->and($request->refresh()->state)->toBe(DataSubjectRequestState::Refused);
});
