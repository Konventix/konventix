<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A hall plan (ADR-0060 point 4, design §4.4): one JSON document per plan
     * holding every zone, group, place, fixture and annotation in integer
     * millimetres, projected into `resources` on every save.
     *
     * The document is stored whole rather than normalised because the editor
     * loads and saves it whole and nothing queries a zone on its own; the
     * projection gives booking logic what it needs. `version` is the
     * optimistic lock: a save carries the version it was based on and a
     * mismatch is refused (409).
     */
    public function up(): void
    {
        Schema::create('resource_plans', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('brand_id')->constrained()->cascadeOnDelete();

            // Event and venue are a different module — plain ULIDs (ADR-0014).
            // Plans are per event (D-EVT-13); v1 never sets a venue.
            $table->ulid('event_id');
            $table->ulid('venue_id')->nullable();

            $table->string('name');
            $table->string('floor_label')->nullable();

            $table->integer('width_mm');
            $table->integer('height_mm');

            // A `BackgroundData` (raster background through the media
            // library) behind the typed cast, or null.
            $table->jsonb('background')->nullable();

            // What the editor displays; the document is always millimetres.
            $table->string('unit_display', 2)->default('cm');
            $table->integer('snap_step')->default(100);

            $table->integer('version')->default(0);

            // `PlanDocumentData` behind laravel-data's Eloquent cast (ADR-0009).
            $table->jsonb('document');

            $table->timestamp('published_at')->nullable();

            // Reserved for plan copy (design §14, "Nach Event" backlog): the
            // source plan's id this one was copied from, analogous to
            // `resources.origin_id` for a copied place. Not a person's id —
            // corrected 2026-09-10; this line previously and wrongly said
            // "a user id, nulled on erasure (GDPR)", which describes
            // `created_by` below, not this column. Always null today
            // because plan copy is not built (`ResourcesGdprHandler` has
            // nothing to touch here yet); whoever ships plan copy should
            // confirm it still holds no personal data before relying on
            // that.
            $table->ulid('origin_id')->nullable();

            // Who created this plan (`Actions\CreatePlan`); nulled on
            // erasure, exported as its own `plans` section — see
            // `ResourcesGdprHandler`.
            $table->ulid('created_by')->nullable();

            $table->timestamps();

            $table->unique(['brand_id', 'event_id', 'name']);
            $table->index(['brand_id', 'event_id', 'published_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('resource_plans');
    }
};
