<?php

declare(strict_types=1);

use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Konventix\Admission\Actions\CheckInFromConsole;
use Konventix\Admission\Enums\CheckInResult;
use Konventix\Admission\Enums\CheckpointKind;
use Konventix\Admission\Models\Admission;
use Konventix\Admission\Models\CheckIn;
use Konventix\Admission\Models\Checkpoint;
use Konventix\Platform\Brands\BrandContext;

/**
 * The console checkpoint (#329, attendee-list design §3.1, §6): one per
 * event, created on the first console check-in and reused after it, flagged
 * `kind = console` so no device enrolment surface ever offers it (#328,
 * ADR-0033 as amended) — every such surface reads `Checkpoint::doors()`.
 *
 * No calendar is projected for these events, so they are not day-scoped and
 * re-entry is unlimited; every console check-in below is accepted.
 */
it('creates the console checkpoint on first use and reuses it for the event', function (): void {
    $brand = $this->withBrand();
    $eventId = (string) Str::ulid();
    $first = Admission::factory()->create(['event_id' => $eventId]);
    $second = Admission::factory()->create(['event_id' => $eventId]);

    // Derivation: before any console check-in the event has no checkpoint.
    expect(Checkpoint::query()->count())->toBe(0);

    $a = app(CheckInFromConsole::class)->handle($first, $eventId, (string) Str::ulid(), 'in');
    $b = app(CheckInFromConsole::class)->handle($second, $eventId, (string) Str::ulid(), 'in');

    // Derivation: the first check-in creates it, the second finds it — one
    // row, named "Console", of kind console, on this brand and event, and
    // both check-ins point at it.
    $console = Checkpoint::query()->sole();

    expect($console->kind)->toBe(CheckpointKind::Console)
        ->and($console->name)->toBe(CheckInFromConsole::CHECKPOINT_NAME)
        ->and($console->event_id)->toBe($eventId)
        ->and($console->brand_id)->toBe($brand->id)
        ->and($a->checkpoint_id)->toBe($console->id)
        ->and($b->checkpoint_id)->toBe($console->id);
});

it('gives each event its own console checkpoint', function (): void {
    $this->withBrand();
    [$eventA, $eventB] = [(string) Str::ulid(), (string) Str::ulid()];

    $a = app(CheckInFromConsole::class)->handle(Admission::factory()->create(['event_id' => $eventA]), $eventA, (string) Str::ulid(), 'in');
    $b = app(CheckInFromConsole::class)->handle(Admission::factory()->create(['event_id' => $eventB]), $eventB, (string) Str::ulid(), 'in');

    // Derivation: two events, one console checkpoint each — two rows, one
    // per event id, and the check-ins point at different ones.
    expect(Checkpoint::query()->where('kind', CheckpointKind::Console->value)->count())->toBe(2)
        ->and(Checkpoint::query()->whereKey($a->checkpoint_id)->value('event_id'))->toBe($eventA)
        ->and(Checkpoint::query()->whereKey($b->checkpoint_id)->value('event_id'))->toBe($eventB)
        ->and($a->checkpoint_id)->not->toBe($b->checkpoint_id);
});

it('leaves the console checkpoint out of doors()', function (): void {
    $this->withBrand();
    $eventId = (string) Str::ulid();
    $door = Checkpoint::factory()->forEvent($eventId)->create();

    app(CheckInFromConsole::class)->handle(Admission::factory()->create(['event_id' => $eventId]), $eventId, (string) Str::ulid(), 'in');

    // Derivation: two checkpoints on the event — the factory's door, which
    // defaults to `door`, and the console's — and doors() is the first only.
    expect(Checkpoint::query()->where('event_id', $eventId)->count())->toBe(2)
        ->and($door->fresh()->kind)->toBe(CheckpointKind::Door)
        ->and(Checkpoint::query()->doors()->pluck('id')->all())->toBe([$door->id]);
});

it('allows one console checkpoint per event in the database, and any number of doors', function (): void {
    $brand = $this->withBrand();
    $eventId = (string) Str::ulid();

    Checkpoint::factory()->forEvent($eventId)->create(['kind' => CheckpointKind::Console]);
    Checkpoint::factory()->forEvent($eventId)->count(2)->create();

    // A savepoint, so the refused INSERT does not abort the test's own
    // transaction on Postgres (.ai/rules/tests.md).
    expect(fn () => DB::transaction(fn () => Checkpoint::factory()->forEvent($eventId)->create(['kind' => CheckpointKind::Console])))
        ->toThrow(UniqueConstraintViolationException::class);

    // Derivation: one console and two doors went in; the second console
    // did not.
    expect(Checkpoint::query()->where('event_id', $eventId)->count())->toBe(3)
        ->and(Checkpoint::query()->where('event_id', $eventId)->doors()->count())->toBe(2)
        ->and(Checkpoint::query()->where('brand_id', $brand->id)->where('kind', 'console')->count())->toBe(1);
});

it('works with no ambient brand, as on the operator admin host', function (): void {
    $brand = $this->withBrand();
    $eventId = (string) Str::ulid();
    $admission = Admission::factory()->create(['event_id' => $eventId]);

    // The admin host resolves no brand (ADR-0029): every read and the
    // checkpoint's create must stand on the admission's own brand.
    app(BrandContext::class)->forget();

    $checkIn = app(CheckInFromConsole::class)->handle($admission, $eventId, (string) Str::ulid(), 'in');

    $this->withBrand($brand);

    // Derivation: accepted (no calendar, unlimited, issued), stamped with the
    // admission's brand, and so is the checkpoint the call created.
    expect($checkIn->result)->toBe(CheckInResult::Accepted)
        ->and(CheckIn::query()->whereKey($checkIn->id)->value('brand_id'))->toBe($brand->id)
        ->and(Checkpoint::query()->whereKey($checkIn->checkpoint_id)->value('brand_id'))->toBe($brand->id);
});
