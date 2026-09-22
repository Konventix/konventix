<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The shoutbox's room (design §4.1, ADR-0067). `subject_type` +
     * `subject_id` is the room's subject — an event today, a match or
     * competition later (the enum ships ready, sub-project B supplies the
     * subjects); `subject_id` is a plain ULID, no FK, because the subject
     * lives in another module (ADR-0014). A room is created lazily on first
     * read, never backfilled, so a brand that never opens the wall carries
     * no row.
     *
     * `locked_at`/`locked_by_user_id`/`locked_reason` hold the room
     * open-but-read-only. `closed_at` is set by `EventConcluded` and is
     * what starts the 30-day retention clock (design §7); a closed room
     * stays readable until the sweep, and the room row itself is never
     * deleted — only its messages, mutes and reports are.
     */
    public function up(): void
    {
        Schema::create('community_rooms', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('brand_id')->constrained()->cascadeOnDelete();

            $table->string('subject_type', 16);
            // Plain ULID, no FK: the subject lives in another module (ADR-0014).
            $table->ulid('subject_id');

            $table->timestampTz('locked_at')->nullable();
            $table->ulid('locked_by_user_id')->nullable();
            $table->string('locked_reason')->nullable();

            $table->timestampTz('closed_at')->nullable();

            $table->timestamps();

            $table->unique(['subject_type', 'subject_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('community_rooms');
    }
};
