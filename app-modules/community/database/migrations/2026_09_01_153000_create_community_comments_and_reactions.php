<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Profile-wall comments and reactions (Task 9), the same subject-triple
     * shape Content's `content_reactions` pioneered (ROADMAP slice 22):
     * `(module, entity_type, entity_id)` instead of a hard foreign key, so
     * slice 26's gallery items can reuse `community_comments` without a
     * migration and reactions can point at either a profile-wall comment or
     * (later) a gallery-item comment through the same table.
     *
     * Today the only comment subject is `('community', 'profile', <profile
     * user ulid>)` and the only reaction subject is `('community', 'comment',
     * <comment ulid>)`.
     *
     * `community_reactions` is a straight clone of `content_reactions`:
     * `UPDATED_AT = null` on the model, `created_at` alone with `useCurrent`,
     * and the same unique quadruple so a double click is one reaction.
     */
    public function up(): void
    {
        Schema::create('community_comments', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('brand_id')->constrained()->cascadeOnDelete();

            $table->string('module', 32);
            $table->string('entity_type', 32);
            $table->ulid('entity_id');

            // Plain ULID, no FK (ADR-0014): accounts are Identity's.
            $table->ulid('author_user_id')->index();

            $table->text('body');

            // Denormalized from community_reactions — a recount, never an
            // increment (Content's `content_comments.reaction_count`
            // precedent).
            $table->unsignedInteger('reaction_count')->default(0);

            $table->timestamps();

            $table->index(['module', 'entity_type', 'entity_id']);
        });

        Schema::create('community_reactions', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('brand_id')->constrained()->cascadeOnDelete();

            // Plain ULID, no FK (ADR-0014): accounts are Identity's.
            $table->ulid('user_id');

            $table->string('module', 32);
            $table->string('entity_type', 32);
            $table->ulid('entity_id');

            $table->timestamp('created_at')->useCurrent();

            $table->unique(['user_id', 'module', 'entity_type', 'entity_id'], 'community_reactions_subject_unique');
            $table->index(['module', 'entity_type', 'entity_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('community_reactions');
        Schema::dropIfExists('community_comments');
    }
};
