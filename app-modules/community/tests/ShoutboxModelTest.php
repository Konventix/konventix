<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Konventix\Community\Enums\ReportState;
use Konventix\Community\Enums\RoomSubject;
use Konventix\Community\Models\Message;
use Konventix\Community\Models\MessageReport;
use Konventix\Community\Models\Room;
use Konventix\Community\Models\RoomMute;

/**
 * The shoutbox's model layer (design §4.1-4.4, task-1-brief.md): rooms,
 * messages, mutes and reports. Nothing renders yet — these tests pin the
 * schema, not behaviour a later task builds. A constraint violation aborts
 * the surrounding Postgres transaction (.ai/rules/tests.md), so every case
 * expecting one runs inside its own `DB::transaction` — a savepoint under
 * RefreshDatabase — before the next assertion queries again.
 */
it('refuses a second room for the same subject', function (): void {
    $this->withBrand();
    $subjectId = (string) Str::ulid();

    Room::factory()->create(['subject_type' => RoomSubject::Event, 'subject_id' => $subjectId]);

    expect(fn () => DB::transaction(fn () => Room::factory()->create([
        'subject_type' => RoomSubject::Event,
        'subject_id' => $subjectId,
    ])))->toThrow(QueryException::class);
});

it('refuses a mute without a reason, at the database', function (): void {
    $this->withBrand();

    // `reason` is fillable and nothing validates it here — only the
    // column's `NOT NULL` stands between this and a row, which is exactly
    // what this test is pinning.
    expect(fn () => DB::transaction(fn () => RoomMute::factory()->create(['reason' => null])))
        ->toThrow(QueryException::class);
});

it('refuses a message longer than 500 characters', function (): void {
    $this->withBrand();

    expect(fn () => DB::transaction(fn () => Message::factory()->create(['body' => str_repeat('a', 501)])))
        ->toThrow(QueryException::class);
});

it('refuses the same reporter reporting one message twice', function (): void {
    $this->withBrand();
    $message = Message::factory()->create();
    $reporterId = (string) Str::ulid();

    MessageReport::factory()->for($message)->create(['reporter_user_id' => $reporterId]);

    expect(fn () => DB::transaction(fn () => MessageReport::factory()->for($message)->create([
        'reporter_user_id' => $reporterId,
    ])))->toThrow(QueryException::class);
});

it('orders messages chronologically by id', function (): void {
    $this->withBrand();
    $room = Room::factory()->create();

    $first = Message::factory()->for($room)->create();
    $second = Message::factory()->for($room)->create();

    expect(Message::query()->where('room_id', $room->id)->orderBy('id')->pluck('id')->all())
        ->toBe([$first->id, $second->id]);
});

it('keeps the tombstone and the lock triple out of mass assignment', function (): void {
    $this->withBrand();

    $room = Room::factory()->create();
    $message = Message::factory()->for($room)->create();

    // No database CHECK ties `deleted_at`/`deleted_by_user_id`/
    // `deleted_reason` together, or the room's three lock columns: the
    // Actions writing all of each triple in one `forceFill` is the only
    // thing that keeps them consistent. So the columns are off `$fillable`,
    // and the next `update()` — a retention sweep, an eraser, an admin
    // quick-action — cannot set one of them in passing. Outside production
    // `Model::shouldBeStrict()` turns that into a throw; in production it is
    // silently discarded. Either way the column is not written, which is
    // the guarantee this pins.
    rescue(fn () => $message->fill(['deleted_at' => CarbonImmutable::now()->utc()]), report: false);
    rescue(fn () => $room->fill(['locked_at' => CarbonImmutable::now()->utc()]), report: false);

    expect($message->deleted_at)->toBeNull()
        ->and($message->isRemoved())->toBeFalse()
        ->and($room->locked_at)->toBeNull();
});

// The same hole on the desk's queue: `state`, `resolved_by_user_id` and
// `resolved_at` are tied together by nothing but `ResolveReport`'s one
// `forceFill`, so none of the three may arrive through `fill()` — a report
// resolved by nobody, or resolved with no state, is a row the desk cannot
// attribute.
it('keeps the report\'s resolve triple out of mass assignment', function (): void {
    $this->withBrand();

    $report = MessageReport::factory()->create();

    rescue(fn () => $report->fill(['state' => ReportState::Resolved]), report: false);
    rescue(fn () => $report->fill(['resolved_by_user_id' => (string) Str::ulid()]), report: false);
    rescue(fn () => $report->fill(['resolved_at' => CarbonImmutable::now()->utc()]), report: false);

    expect($report->state)->toBe(ReportState::Open)
        ->and($report->resolved_by_user_id)->toBeNull()
        ->and($report->resolved_at)->toBeNull()
        ->and($report->getFillable())->toBe(['message_id', 'reporter_user_id', 'note']);
});
