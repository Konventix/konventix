<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A timed, per-room mute (design §4.3): Twitch-shaped, and deliberately
     * so. `reason` is `NOT NULL` at the database level, not only validated —
     * a silent mute reads as a broken app, and the reason is shown to the
     * muted person. `expires_at` null means "for the rest of the event".
     *
     * This is a log, not a current-state row: a person may carry several
     * mutes over an event's life, so there is no uniqueness on
     * `(room_id, user_id)`. `room_id` is a real FK — same module (ADR-0014)
     * — while `user_id` and `muted_by_user_id` are plain ULIDs, because
     * accounts are Identity's.
     *
     * `index(room_id, user_id, expires_at)` is the "is this person muted
     * right now in this room" read.
     */
    public function up(): void
    {
        Schema::create('community_room_mutes', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('brand_id')->constrained()->cascadeOnDelete();

            $table->foreignUlid('room_id')->constrained('community_rooms')->cascadeOnDelete();
            // Plain ULIDs, no FK: accounts are Identity's (ADR-0014).
            $table->ulid('user_id');
            $table->ulid('muted_by_user_id');

            $table->string('reason');
            $table->timestampTz('expires_at')->nullable();

            $table->timestamps();

            $table->index(['room_id', 'user_id', 'expires_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('community_room_mutes');
    }
};
