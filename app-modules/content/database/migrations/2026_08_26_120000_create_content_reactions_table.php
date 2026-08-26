<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Reactions (ROADMAP slice 22). The subject is a `(module, entity_type,
     * entity_id)` triple — the shape `media_usages` already uses, string
     * labels rather than class-strings — instead of a foreign key to
     * `content_comments`, because the comment table's hard FK to
     * `content_entries` is already the reason gallery comments cannot reuse
     * it (slice 26). Today the only subject is a comment.
     *
     * One row per (user, subject): the count on the comment is recounted
     * from these rows, never incremented, so a replayed request converges.
     */
    public function up(): void
    {
        Schema::create('content_reactions', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('brand_id')->constrained()->cascadeOnDelete();

            // Plain ULID, no FK (ADR-0014): accounts are Identity's.
            $table->ulid('user_id');

            $table->string('module', 32);
            $table->string('entity_type', 32);
            $table->ulid('entity_id');

            $table->timestamp('created_at')->useCurrent();

            $table->unique(['user_id', 'module', 'entity_type', 'entity_id'], 'content_reactions_subject_unique');
            $table->index(['module', 'entity_type', 'entity_id']);
        });

        Schema::table('content_comments', function (Blueprint $table): void {
            // Denormalized from content_reactions — a recount, not a counter.
            $table->unsignedInteger('reaction_count')->default(0)->after('report_count');
        });
    }

    public function down(): void
    {
        Schema::table('content_comments', function (Blueprint $table): void {
            $table->dropColumn('reaction_count');
        });

        Schema::dropIfExists('content_reactions');
    }
};
