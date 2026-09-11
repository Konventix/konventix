<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * `resources` becomes the projection of a plan (ADR-0060 point 2, design
     * §4.2): a place is a unit-mode resource whose id equals the element id
     * in the plan document. The columns here are what booking logic and the
     * picker need without reading the document; the geometry stays there.
     *
     * `deleted_at` is `SoftDeletes`: a place the operator removed keeps its
     * booking history. The ref uniqueness is therefore partial — a deleted
     * "A-01" must not block the next "A-01" — which Blueprint cannot
     * express, hence the hand-written index.
     *
     * `plan_id` is `restrictOnDelete`: a plan with places is deleted through
     * an Action that resolves their bookings first, never by cascade.
     */
    public function up(): void
    {
        Schema::table('resources', function (Blueprint $table): void {
            $table->foreignUlid('plan_id')->nullable()->constrained('resource_plans')->restrictOnDelete();

            // The human key from the label schema ("A-01"), unique per plan
            // among live rows.
            $table->string('ref', 64)->nullable();

            // Element ids of the enclosing zone and group, derived from the
            // parent chain on every save.
            $table->ulid('zone_id')->nullable();
            $table->ulid('group_id')->nullable();

            $table->boolean('accessible')->default(false);

            // Operator lock: availability `unavailable`, assignment refuses.
            $table->boolean('blocked')->default(false);

            // list<string> of neighbouring resource ids, computed on save,
            // unused in v1.
            $table->jsonb('neighbors')->nullable();

            // Reserved for copies.
            $table->ulid('origin_id')->nullable();

            $table->softDeletes();

            $table->index(['plan_id', 'zone_id', 'group_id']);
        });

        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX resources_plan_ref_unique
            ON resources (plan_id, ref)
            WHERE deleted_at IS NULL
        SQL);
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS resources_plan_ref_unique');

        Schema::table('resources', function (Blueprint $table): void {
            $table->dropIndex(['plan_id', 'zone_id', 'group_id']);
            $table->dropConstrainedForeignId('plan_id');
            $table->dropSoftDeletes();
            $table->dropColumn(['ref', 'zone_id', 'group_id', 'accessible', 'blocked', 'neighbors', 'origin_id']);
        });
    }
};
