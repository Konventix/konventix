<?php

declare(strict_types=1);

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
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
